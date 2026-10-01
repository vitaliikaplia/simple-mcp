<?php
/**
 * Block toolset — safe reading/editing of ACF-in-Gutenberg block content.
 *
 * In ACF+Timber themes almost all page content lives INLINE in the post_content
 * block-delimiter JSON (flattened ACF data with a _name=>field_key mirror, \uXXXX-escaped
 * HTML), NOT in post meta. acf_update() cannot reach it. These tools run parse_blocks()/
 * serialize_blocks() server-side and resolve field_keys from the ACF registry at runtime,
 * so an AI never hand-assembles delimiter JSON.
 *
 * Every write: writable_post → with_post_lock → re-read the post INSIDE the lock → if_match
 * precondition → validate everything (nothing is written on any error) → modify the parsed
 * tree → one Simple_MCP_Tools::save_post_content (rollback point + wp_slash + verify).
 *
 * Values are handled in ACF's nested shape (repeater = list of row objects, group/clone =
 * object, flexible = rows with acf_fc_layout) and stored the way ACF's own editor save stores
 * them (see flatten(); checked against acf_setup_meta() capture for repeaters, groups,
 * display-group and seamless clones and flexible content).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Blocks {

    /** Field types that hold no value. */
    const NON_STORABLE = ['accordion', 'tab', 'message'];

    /** ACF's own field types; any other type is a custom one whose update_value() normalizes input. */
    const ACF_TYPES = ['text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'image', 'file', 'wysiwyg',
        'oembed', 'gallery', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'link', 'post_object',
        'page_link', 'relationship', 'taxonomy', 'user', 'google_map', 'date_picker', 'date_time_picker', 'time_picker',
        'color_picker', 'icon_picker', 'message', 'accordion', 'tab', 'group', 'repeater', 'flexible_content', 'clone',
        'separator', 'output'];

    const LOCATOR = [
        'type' => 'object', 'additionalProperties' => false,
        'description' => 'Which block to target. Give at least one criterion; EVERY criterion given must match the SAME block, otherwise the call fails (nothing is written).',
        'properties' => [
            'path'      => ['type' => ['string', 'integer'], 'description' => 'block path from block_get: "2" = top-level block 2, "2.0" = its first inner block, "2.0.1" = deeper'],
            'index'     => ['type' => 'integer', 'minimum' => 0, 'description' => 'ordinal among top-level blocks (block_get index)'],
            'anchor'    => ['type' => 'string', 'description' => 'non-empty block anchor, searched at every depth; an anchor carried by several blocks is an error that lists their paths'],
            'blockName' => ['type' => 'string', 'description' => 'e.g. acf/main-first-screen; alone (optionally with nth) it selects among TOP-LEVEL blocks'],
            'nth'       => ['type' => 'integer', 'minimum' => 0, 'description' => '0-based index among top-level blocks named blockName (default 0); only valid with blockName alone'],
        ],
    ];

    const IF_MATCH = ['type' => 'string', 'description' => 'content_etag from block_get or a previous write; the call is refused if the post body changed since'];
    const DRY_RUN  = ['type' => 'boolean', 'description' => 'validate and compute the result without saving'];

    /** Core blocks that never hold inner blocks (parent_path / innerBlocks into them is refused). */
    const CORE_LEAVES = ['core/paragraph', 'core/heading', 'core/image', 'core/html', 'core/code', 'core/preformatted',
        'core/verse', 'core/separator', 'core/spacer', 'core/shortcode', 'core/freeform', 'core/table', 'core/pullquote',
        'core/button', 'core/file', 'core/audio', 'core/video', 'core/embed', 'core/more', 'core/nextpage', 'core/missing'];

    const SPEC = ['type' => 'object', 'description' => '{blockName, data?: {field: value}, attrs?: {anchor, className, align, ...}, innerBlocks?: [spec, ...], html?: inner HTML (non-ACF blocks), mode?: preview|edit|auto (ACF blocks)}'];

    static function annotations($read_only, $destructive, $idempotent) {
        return ['readOnlyHint' => $read_only, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => false];
    }

    static function defs() {
        $position = ['type' => ['integer', 'string'], 'description' => 'integer >= 0 among the sibling blocks, or "start"/"end" (case-insensitive; default "end")'];
        $parent   = ['type' => ['string', 'integer'], 'description' => 'path of a block (from block_get) to work inside its innerBlocks instead of the top level'];
        // wp-loc attribute/term sync: every save of a translation propagates to its siblings
        $sync = (class_exists('Simple_MCP_Tools_Wploc') && Simple_MCP::multilingual_system() && method_exists('Simple_MCP_Tools_Wploc', 'sync_note'))
            ? Simple_MCP_Tools_Wploc::sync_note() : null;
        // shared tail of the write tools' descriptions (block_update starts with the full sync warning;
        // descriptions stay under ~2000 characters: Claude Code cuts longer ones at 2048)
        $tail = ' Trashed posts and types whose post_content is not block markup are refused. A call that leaves the body unchanged saves nothing (unchanged:true). When save filters alter the body (kses without unfiltered_html) content_verified is false and filtered_blocks lists the affected top-level paths.';
        $lead_update = $sync ? $sync . ' Results then carry sync_note. ' : '';
        $notes = $tail . ($sync ? ' On this multilingual site saved results carry sync_note when the post has translations (the save pushes attributes/terms to them).' : '');
        return [
            'block_get' => [
                'title' => 'Read page blocks',
                'description' => 'Read a post body as its Gutenberg blocks. Each block has path ("2" = top-level block 2, "2.0" = its first inner block), index (top-level blocks only), blockName, isACF, anchor, innerBlocks (count) and children (its inner blocks, described the same way, so ACF blocks nested in groups/columns are reachable). Non-ACF blocks return html (a 200-character text excerpt) and attrs. ACF blocks return mode, fields (the stored values flattened the way ACF keeps them inline in post_content: tiles_0_title, bg_video_mp4; "_" reference keys hidden) and, when present, broken_refs (stored field references ACF cannot resolve) and disabled_rows (flexible-content rows an editor disabled, hidden on the front end: {field: [row, ...]}). uses_post_meta:true marks an ACF block whose values live in post meta instead (read/write those with acf_get/acf_update on the post). nested:true adds values: the same data in exactly the nested shape block_update accepts (repeaters as lists of row objects, groups/clones as objects, flexible rows with acf_fc_layout). Returns content_etag: pass it as if_match to the block_* write tools so they refuse to overwrite a body that changed after this read. ACF block values live inline in post_content, not post meta, so acf_get cannot read them.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'nested'  => ['type' => 'boolean', 'description' => 'also return values in the nested shape block_update accepts (default false)'],
                    ],
                    'required' => ['post_id']],
                'annotations' => self::annotations(true, false, true),
                'callback' => [__CLASS__, 'block_get'],
            ],
            'list_block_fields' => [
                'title' => 'ACF block field schema',
                'description' => 'Return the ACF field schema of a registered ACF block type, read from the ACF registry at runtime: per field name, key (the storable field_key; for seamless-clone fields the field\'s own key, not the in-memory <clone>_<field> one), type, label, required, default, choices, multiple, min/max, clone (key of the seamless clone a field comes from), sub_fields and layouts. Includes the shared block-settings group (padding/margin/bg/anchor) whose keys differ per fork. Also returns block_defaults (the default data the block type registers, by field name; block_insert applies it), accepts_inner_blocks and uses_post_meta. Use it to learn valid names, value shapes and choices before block_update/block_insert/block_replace.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['block_name' => ['type' => 'string', 'description' => 'e.g. "acf/main-first-screen" or "main-first-screen"']],
                    'required' => ['block_name']],
                'annotations' => self::annotations(true, false, true),
                'callback' => [__CLASS__, 'list_block_fields'],
            ],
            'block_update' => [
                'title' => 'Edit ACF block fields',
                'description' => $lead_update . 'Edit ACF field values of ONE block in place, at any depth (locator from block_get). set maps field NAMES (not keys) or flat paths (tiles_0_title, bg_video_mp4) to values (shapes: see set). Merge by default: a group/clone merges into the current value; a repeater list merges row by row (rows beyond it are removed, new rows get defaults); a flexible row merges while its acf_fc_layout is unchanged; null deletes; a flat path edits a value in an EXISTING row; a row path set to null (tiles_2) removes that row. Row indexes in one set refer to the block BEFORE the call (removals apply last); a key inside a removed row or inside a list/field another key replaces is refused. replace:true writes each field exactly as given (no merge, no defaults). All input is validated first (fields, sub-fields, shapes, referenced objects, choices, formats); on any error nothing is written and every problem is listed with its path. Field references are stored as ACF stores them (refs_repaired: broken pre-2.4.0 refs fixed). Returns fields_updated, keys_changed, keys_removed, content_verified, content_etag, editor_lock_warning, disabled_rows (flexible rows an editor disabled; acf_fc_layout_disabled:false shows one); dry_run:true adds changes {key: {old, new}} without saving. use_post_meta blocks are refused (use acf_update).' . $tail,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'  => ['type' => 'integer'],
                        'locator'  => self::LOCATOR,
                        'set'      => ['type' => 'object', 'description' => 'field name or flat path => new value (null deletes; a row path such as tiles_2 => null removes the row). Shapes: text = string; number/range = number; true_false = true/false; image/file = attachment ID ("" clears); gallery = [IDs]; link = {url, title?, target?} or ""; select/checkbox/radio/button_group = choice value(s); post_object/relationship/page_link/taxonomy/user = ID(s); date_picker = Ymd or Y-m-d (stored Ymd); date_time_picker = Y-m-d H:i[:s]; time_picker = H:i[:s]; repeater = [{sub: value}, ...]; group/clone = {sub: value}; flexible = [{acf_fc_layout: name, sub: value}, ...].'],
                        'replace'  => ['type' => 'boolean', 'description' => 'write each given field exactly as given instead of merging (default false)'],
                        'dry_run'  => self::DRY_RUN,
                        'if_match' => self::IF_MATCH,
                    ],
                    'required' => ['post_id', 'locator', 'set']],
                // not idempotent: a row path set to null (tiles_2) removes another row on every call
                'annotations' => self::annotations(false, true, false),
                'callback' => [__CLASS__, 'block_update'],
            ],
            'block_insert' => [
                'title' => 'Insert a block',
                'description' => 'Insert a new block built from a spec {blockName, data?, attrs?, innerBlocks?, html?, mode?}. blockName must be a registered ACF block ("acf/main-text" or "main-text") or core block ("core/paragraph" or "paragraph"); unknown names are refused. ACF data is given by field name in the nested shape block_update accepts and validated the same way; every storable field you omit gets its default_value and the block type\'s registered default data is applied, as the editor does (listed in defaults_applied). attrs passes extra block attributes (anchor, className, align, ...); name/mode/data attributes are written only for ACF blocks; html is the inner HTML of non-ACF blocks (a core container such as core/group needs its wrapper element, e.g. <div class="wp-block-group"></div>: inner blocks go before its last closing tag). An invalid inner block, or inner blocks in a block that cannot hold them (core/paragraph, an ACF block without jsx support, ...), is an error, never silently dropped. position: integer >= 0 among the siblings, "start" or "end" (default "end"); parent_path inserts into that block\'s innerBlocks. Blank-line separators are added as the editor writes them. Nothing is written if anything is invalid. Returns the new path, defaults_applied, content_verified, content_etag and editor_lock_warning (another user has the post open in the editor); dry_run:true also returns the built block without saving.' . $notes,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'     => ['type' => 'integer'],
                        'block'       => self::SPEC,
                        'position'    => $position,
                        'parent_path' => $parent,
                        'dry_run'     => self::DRY_RUN,
                        'if_match'    => self::IF_MATCH,
                    ],
                    'required' => ['post_id', 'block']],
                'annotations' => self::annotations(false, false, false),
                'callback' => [__CLASS__, 'block_insert'],
            ],
            'block_move' => [
                'title' => 'Move a block',
                'description' => 'Move a block among its siblings: from and to are indices among the top-level blocks (block_get index), or among the innerBlocks of parent_path; to is the block\'s final index. Blank-line separators are kept tidy. Returns content_verified, the new content_etag and editor_lock_warning when another user has the post open in the block editor; dry_run:true returns the resulting outline without saving.' . $notes,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'     => ['type' => 'integer'],
                        'from'        => ['type' => 'integer', 'minimum' => 0],
                        'to'          => ['type' => 'integer', 'minimum' => 0],
                        'parent_path' => $parent,
                        'dry_run'     => self::DRY_RUN,
                        'if_match'    => self::IF_MATCH,
                    ],
                    'required' => ['post_id', 'from', 'to']],
                'annotations' => self::annotations(false, true, false),
                'callback' => [__CLASS__, 'block_move'],
            ],
            'block_remove' => [
                'title' => 'Remove a block',
                'description' => 'Remove ONE block, at any depth, identified by a locator (from block_get), together with one adjacent blank separator. A rollback point (revision, or a backup when revisions are off) is kept. Returns the removed blockName and path, content_verified, the new content_etag and editor_lock_warning when another user has the post open in the block editor; dry_run:true returns the resulting outline without saving.' . $notes,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'  => ['type' => 'integer'],
                        'locator'  => self::LOCATOR,
                        'dry_run'  => self::DRY_RUN,
                        'if_match' => self::IF_MATCH,
                    ],
                    'required' => ['post_id', 'locator']],
                'annotations' => self::annotations(false, true, false),
                'callback' => [__CLASS__, 'block_remove'],
            ],
            'block_replace' => [
                'title' => 'Replace the whole body',
                'description' => 'Replace the ENTIRE post body with blocks built from specs [{blockName, data?, attrs?, innerBlocks?, html?, mode?}] — same rules, validation and defaults as block_insert; every spec is validated first and nothing is written if any is invalid. An empty list is refused unless allow_empty:true. Returns blocks_written, discarded (how many blocks and non-blank freeform chunks the old body had), defaults_applied, content_verified, the new content_etag and editor_lock_warning when another user has the post open in the block editor. Destructive: a rollback point (revision or backup) is kept; prefer block_update/block_insert for edits.' . $notes,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'     => ['type' => 'integer'],
                        'blocks'      => ['type' => 'array', 'items' => self::SPEC],
                        'allow_empty' => ['type' => 'boolean', 'description' => 'required to write an empty body'],
                        'dry_run'     => self::DRY_RUN,
                        'if_match'    => self::IF_MATCH,
                    ],
                    'required' => ['post_id', 'blocks']],
                'annotations' => self::annotations(false, true, true),
                'callback' => [__CLASS__, 'block_replace'],
            ],
            'block_batch' => [
                'title' => 'Batch block edits',
                'description' => 'Apply several block operations to one post atomically. ops is an ordered list of {op: "update"|"insert"|"move"|"remove", ...} with the same parameters as block_update (locator, set, replace), block_insert (block, position, parent_path), block_move (from, to, parent_path) and block_remove (locator), without post_id/if_match/dry_run. Ops run in order on one in-memory copy of the body, so each locator or index sees the result of the previous ops. Every op is validated; if any op fails nothing is written and all errors are listed. On success the post is saved ONCE (one revision). Returns per-op results, content_verified, the new content_etag and editor_lock_warning when another user has the post open in the block editor; dry_run:true runs everything without saving.' . $notes,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'  => ['type' => 'integer'],
                        'ops'      => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => [
                            'op' => ['type' => 'string', 'enum' => ['update', 'insert', 'move', 'remove']],
                        ], 'required' => ['op']]],
                        'dry_run'  => self::DRY_RUN,
                        'if_match' => self::IF_MATCH,
                    ],
                    'required' => ['post_id', 'ops']],
                'annotations' => self::annotations(false, true, false),
                'callback' => [__CLASS__, 'block_batch'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    /** Validation failure: the message lists every problem; nothing is written. */
    static function fail($msg, $errors = []) {
        if ($errors) $msg .= "\n- " . implode("\n- ", $errors);
        throw new \InvalidArgumentException($msg);
    }

    // ── READ ──────────────────────────────────────────────────────────────

    static function block_get($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        if (!Simple_MCP_Tools::can_read_post($post)) return Simple_MCP_Tools::err_cap('read post #' . $post_id);
        $etag = Simple_MCP_Tools::content_etag($post_id); // fresh read; the body below is the same one
        $post = get_post($post_id);
        $nested = !empty($args['nested']);
        $out = [];
        $i = 0;
        foreach (parse_blocks($post->post_content) as $b) {
            if (empty($b['blockName'])) continue;
            $out[] = self::describe($b, (string) $i, $nested, $i);
            $i++;
        }
        return self::ok(['post_id' => $post_id, 'post_type' => $post->post_type, 'content_etag' => $etag, 'block_count' => count($out), 'blocks' => $out]);
    }

    static function describe($b, $path, $nested = false, $index = null) {
        $name  = (string) $b['blockName'];
        $attrs = is_array($b['attrs'] ?? null) ? $b['attrs'] : [];
        $isACF = self::is_acf_block($name);
        $e = [];
        if ($index !== null) $e['index'] = $index;
        $e += [
            'path' => $path, 'blockName' => $name, 'isACF' => $isACF,
            'anchor' => self::anchor_of($b),
            'innerBlocks' => count($b['innerBlocks'] ?? []),
        ];
        $data = is_array($attrs['data'] ?? null) ? $attrs['data'] : null;
        if ($isACF || ($data !== null && strpos($name, 'acf/') === 0)) {
            if (!$isACF) $e['registered'] = false; // acf/* block whose type is gone: raw data only
            $e['mode'] = $attrs['mode'] ?? null;
            if ($isACF && self::uses_post_meta($name)) {
                $e['uses_post_meta'] = true;
                $e['note'] = 'This block keeps its field values in post meta, not in post_content: read/write them with acf_get/acf_update on the post. block_update refuses it.';
            }
            $data = (array) $data;
            $fields = [];
            foreach ($data as $k => $v) {
                if ($k === '' || $k[0] === '_') continue;
                $fields[$k] = $v;
            }
            $e['fields'] = $fields;
            if ($nested && $isACF) $e['values'] = (object) self::unflatten(array_values(self::block_field_defs($name)), $data, '');
            $broken = self::broken_refs($data);
            if ($broken) $e['broken_refs'] = $broken;
            $off = self::disabled_rows($data);
            if ($off) $e['disabled_rows'] = $off;
            $extra = array_diff_key($attrs, ['name' => 1, 'data' => 1, 'mode' => 1]);
            if ($extra) $e['attrs'] = $extra;
        } else {
            $e['html'] = mb_substr(trim(wp_strip_all_tags($b['innerHTML'] ?? '')), 0, 200);
            if ($attrs) $e['attrs'] = $attrs;
        }
        if (!empty($b['innerBlocks'])) {
            $e['children'] = [];
            foreach (array_values($b['innerBlocks']) as $j => $c) {
                $e['children'][] = self::describe($c, $path . '.' . $j, $nested);
            }
        }
        return $e;
    }

    static function list_block_fields($args) {
        if (!current_user_can('edit_posts')) return Simple_MCP_Tools::err_cap('edit_posts');
        $bn = trim((string) ($args['block_name'] ?? ''));
        if ($bn === '') return self::err('block_name required');
        if (strpos($bn, '/') === false) $bn = 'acf/' . $bn;
        if (!function_exists('acf_get_field_groups')) return self::err('ACF not active');
        if (!self::is_acf_block($bn)) return self::err('unknown ACF block type ' . $bn . '. Registered: ' . implode(', ', self::acf_block_names()));
        $groups = self::block_groups($bn);
        $fields = [];
        $titles = [];
        foreach ($groups as $g) {
            $titles[] = $g['title'];
            $fields = array_merge($fields, self::map_fields(acf_get_fields($g['key'])));
        }
        return self::ok([
            'block_name' => $bn, 'groups' => $titles, 'fields' => $fields,
            'block_defaults' => (object) self::type_defaults($bn, self::block_field_defs($bn)),
            'accepts_inner_blocks' => self::accepts_inner($bn),
            'uses_post_meta' => self::uses_post_meta($bn),
        ]);
    }

    static function map_fields($fields) {
        $out = [];
        foreach ((array) $fields as $f) {
            if (in_array($f['type'] ?? '', self::NON_STORABLE, true)) continue;
            if (($f['name'] ?? '') === '') continue;
            // storable key: a field that reaches the group through a seamless clone carries a
            // temporary "<clone>_<field>" key in memory; the real one is in __key (see flatten()).
            $e = ['name' => $f['name'], 'key' => self::field_ref($f), 'type' => $f['type']];
            if (!empty($f['label']))    $e['label']    = $f['label'];
            if (!empty($f['required'])) $e['required'] = true;
            if (isset($f['default_value']) && $f['default_value'] !== '' && $f['default_value'] !== null) $e['default'] = $f['default_value'];
            if (!empty($f['choices']) && is_array($f['choices'])) $e['choices'] = array_map('strval', array_keys($f['choices']));
            if (!empty($f['multiple'])) $e['multiple'] = true;
            if (($f['type'] ?? '') === 'taxonomy') $e['multiple'] = in_array($f['field_type'] ?? '', ['checkbox', 'multi_select'], true);
            if (in_array($f['type'] ?? '', ['number', 'range'], true)) {
                if (is_numeric($f['min'] ?? '')) $e['min'] = $f['min'] + 0;
                if (is_numeric($f['max'] ?? '')) $e['max'] = $f['max'] + 0;
            }
            if (!empty($f['_clone'])) $e['clone'] = $f['_clone'];
            if (!empty($f['sub_fields'])) $e['sub_fields'] = self::map_fields($f['sub_fields']);
            if (!empty($f['layouts'])) {
                $e['layouts'] = [];
                foreach ($f['layouts'] as $lay) {
                    $e['layouts'][] = ['name' => $lay['name'], 'label' => $lay['label'] ?? '', 'sub_fields' => self::map_fields($lay['sub_fields'] ?? [])];
                }
            }
            $out[] = $e;
        }
        return $out;
    }

    // ── WRITE ─────────────────────────────────────────────────────────────

    /**
     * Shared write pipeline: writable_post → with_post_lock → fresh re-read INSIDE the lock →
     * if_match → $op(&$blocks, $post, $dry) mutates the parsed tree (throws on invalid input,
     * leaving the post untouched) → one save_post_content. Returns the MCP result.
     */
    static function run_write($args, callable $op) {
        $post = Simple_MCP_Tools::writable_post($args['post_id'] ?? 0, true); // trash is refused below with a block-specific message
        if (is_array($post)) return $post;
        $post_id = (int) $post->ID;
        if (!self::block_content_type($post->post_type)) {
            return self::err('post #' . $post_id . ' (post type ' . $post->post_type . ') keeps no block markup in post_content (no editor support, or internal JSON/settings): the block tools do not write it');
        }
        if ($post->post_status === 'trash') {
            // as in wp-admin (and update_post): a trashed item is not editable — on wp-loc its save
            // would also push the "trash" status to every translation of the post
            return self::err('post #' . $post_id . ' is in the trash: restore it first (update_post with a non-trash status), then edit its blocks');
        }
        $dry = !empty($args['dry_run']);
        return Simple_MCP_Tools::with_post_lock($post_id, function () use ($post_id, $args, $op, $dry) {
            clean_post_cache($post_id);
            $post = get_post($post_id);
            if (!$post) return self::err('post #' . $post_id . ' not found');
            $pre = Simple_MCP_Tools::precondition($post_id, $args);
            if ($pre) return $pre;
            $before = (string) $post->post_content;
            $blocks = parse_blocks($before);
            try {
                $res = $op($blocks, $post, $dry);
            } catch (\InvalidArgumentException $e) {
                return self::err($e->getMessage());
            } catch (\RuntimeException $e) {
                return self::err($e->getMessage());
            }
            $content = serialize_blocks($blocks);
            $out = ['post_id' => $post_id] + $res;
            $lock = self::edit_lock_warning($post_id);
            if ($lock) $out['editor_lock_warning'] = $lock;
            $sent = parse_blocks($content);
            $changed = !self::same($sent, parse_blocks($before));
            if ($dry) {
                $out['dry_run'] = true;
                $out['would_change'] = $changed;
                $out['content_etag'] = Simple_MCP_Tools::content_etag($post_id);
                return self::ok($out);
            }
            if (!$changed) { // nothing to store: no save, no revision
                $out['unchanged'] = true;
                $out['content_verified'] = true;
                $out['content_etag'] = Simple_MCP_Tools::content_etag($post_id);
                return self::ok($out);
            }
            $verified = Simple_MCP_Tools::save_post_content($post_id, $content);
            if (is_wp_error($verified)) return self::err('save failed: ' . $verified->get_error_message());
            $out['content_verified'] = $verified;
            $out['content_etag'] = Simple_MCP_Tools::content_etag($post_id);
            $sync = self::sync_note_for($post);
            if ($sync) $out['sync_note'] = $sync;
            if ($verified !== true) {
                $saved = parse_blocks((string) get_post($post_id)->post_content);
                $out['filtered_blocks'] = self::diff_paths($sent, $saved);
                if (!current_user_can('unfiltered_html')) {
                    $out['kses_note'] = 'The saved body differs from what was sent because your account lacks the unfiltered_html capability: WordPress kses filtered the WHOLE post on save (it can strip markup such as iframes/scripts/style attributes and re-encode characters in every block, not only the one you edited). filtered_blocks lists the top-level paths that changed. Check them with block_get; the rollback point (revision or backup) holds the previous body.';
                }
            }
            return self::ok($out);
        });
    }

    /**
     * Another user has the post open in the block editor (fresh _edit_lock, same window as
     * wp_check_post_lock): the editor holds its own copy of the whole body and its next save
     * writes it back, so warn — the MCP lock only serializes MCP calls.
     */
    static function edit_lock_warning($post_id) {
        $lock = get_post_meta($post_id, '_edit_lock', true);
        if (!is_string($lock) || strpos($lock, ':') === false) return null;
        list($time, $user) = array_map('intval', explode(':', $lock, 2));
        $window = (int) apply_filters('wp_check_post_lock_window', 150);
        if (!$user || $user === get_current_user_id() || $time < time() - $window) return null;
        $u = get_userdata($user);
        return 'User "' . ($u ? $u->user_login : '#' . $user) . '" has this post open in the block editor (active ' . max(0, time() - $time) . 's ago): saving there writes their copy of the whole body back and would undo this change unless they reload first.';
    }

    /**
     * Post types whose post_content is block markup: the type supports the editor, and it is not
     * one of core's internal types that keep JSON or other data there (global styles, menu items,
     * oEmbed cache, privacy requests …). ACF's own field-group/field types lack editor support.
     */
    static function block_content_type($pt) {
        if (in_array($pt, ['wp_global_styles', 'nav_menu_item', 'oembed_cache', 'user_request', 'customize_changeset', 'custom_css', 'wp_font_family', 'wp_font_face'], true)) return false;
        return post_type_supports($pt, 'editor');
    }

    /** wp-loc attribute/term sync warning for a saved post that has translations (null otherwise). */
    static function sync_note_for($post) {
        if (!$post || !class_exists('Simple_MCP_Tools_Wploc') || !Simple_MCP::multilingual_system()) return null;
        if (!method_exists('Simple_MCP_Tools_Wploc', 'sync_note') || !method_exists('Simple_MCP_Tools_Wploc', 'siblings')) return null;
        $note = Simple_MCP_Tools_Wploc::sync_note();
        if (!$note || !Simple_MCP_Tools_Wploc::siblings($post->ID, 'post_' . $post->post_type)) return null;
        return (string) $note;
    }

    static function block_update($args) {
        return self::run_write($args, function (array &$blocks, $post, $dry) use ($args) {
            return self::op_update($blocks, $args, $dry);
        });
    }

    static function block_insert($args) {
        return self::run_write($args, function (array &$blocks, $post, $dry) use ($args) {
            return self::op_insert($blocks, $args, $dry);
        });
    }

    static function block_move($args) {
        return self::run_write($args, function (array &$blocks, $post, $dry) use ($args) {
            return self::op_move($blocks, $args, $dry);
        });
    }

    static function block_remove($args) {
        return self::run_write($args, function (array &$blocks, $post, $dry) use ($args) {
            return self::op_remove($blocks, $args, $dry);
        });
    }

    static function block_replace($args) {
        $specs = $args['blocks'] ?? null;
        if (!is_array($specs) || !array_is_list($specs)) return self::err('"blocks" must be a list of block specs');
        if (!$specs && empty($args['allow_empty'])) return self::err('"blocks" is empty: that would blank the whole page. Pass allow_empty:true if that is really intended.');
        return self::run_write($args, function (array &$blocks, $post, $dry) use ($specs) {
            $errors = [];
            $defaults = [];
            $built = [];
            foreach ($specs as $i => $s) {
                $bb = self::build_block($s, 'blocks[' . $i . ']', $errors, $defaults);
                if ($bb) $built[] = $bb;
            }
            if ($errors) self::fail('Nothing was written: invalid block spec(s).', $errors);
            $discarded = ['blocks' => 0, 'freeform_chunks' => 0];
            foreach ($blocks as $b) {
                if (!empty($b['blockName'])) $discarded['blocks']++;
                elseif (trim((string) ($b['innerHTML'] ?? '')) !== '') $discarded['freeform_chunks']++;
            }
            $blocks = [];
            foreach ($built as $i => $bb) {
                if ($i) $blocks[] = self::separator();
                $blocks[] = $bb;
            }
            $out = ['blocks_written' => count($built), 'discarded' => $discarded];
            if ($defaults) $out['defaults_applied'] = $defaults;
            if ($dry) $out['outline'] = self::outline($blocks);
            return $out;
        });
    }

    static function block_batch($args) {
        $ops = $args['ops'] ?? null;
        if (!is_array($ops) || !$ops || !array_is_list($ops)) return self::err('"ops" must be a non-empty list of {op, ...}');
        return self::run_write($args, function (array &$blocks, $post, $dry) use ($ops) {
            $allowed = [
                'update' => ['locator', 'set', 'replace'],
                'insert' => ['block', 'position', 'parent_path'],
                'move'   => ['from', 'to', 'parent_path'],
                'remove' => ['locator'],
            ];
            $errors = [];
            $results = [];
            foreach ($ops as $i => $o) {
                $tag = 'ops[' . $i . ']';
                if (!self::is_object_like($o) || !isset($allowed[$o['op'] ?? ''])) {
                    $errors[] = $tag . ': must be an object with op "update", "insert", "move" or "remove"';
                    continue;
                }
                $kind = $o['op'];
                $unknown = array_diff(array_keys($o), array_merge(['op'], $allowed[$kind]));
                if ($unknown) {
                    $errors[] = $tag . ' (' . $kind . '): unknown parameter(s) ' . implode(', ', $unknown) . ' (allowed: ' . implode(', ', $allowed[$kind]) . '; post_id/if_match/dry_run belong to the batch itself)';
                    continue;
                }
                $snap = $blocks;
                try {
                    $fn = 'op_' . $kind;
                    $results[] = ['op' => $kind] + self::$fn($blocks, $o, $dry);
                } catch (\InvalidArgumentException $e) {
                    $blocks = $snap;
                    $errors[] = $tag . ' (' . $kind . '): ' . $e->getMessage();
                } catch (\RuntimeException $e) {
                    $blocks = $snap;
                    $errors[] = $tag . ' (' . $kind . '): ' . $e->getMessage();
                }
            }
            if ($errors) self::fail('Nothing was written: ' . count($errors) . ' of ' . count($ops) . ' op(s) failed.', $errors);
            $out = ['ops' => $results];
            if ($dry) $out['outline'] = self::outline($blocks);
            return $out;
        });
    }

    // ── OPERATIONS (shared by the single tools and block_batch; throw on invalid input) ──

    static function op_update(array &$blocks, $args, $dry) {
        $set = $args['set'] ?? null;
        if (!self::is_object_like($set) || !$set) self::fail('"set" must be a non-empty object {field_name_or_flat_path: value, ...}');
        $loc = self::locate($blocks, $args['locator'] ?? null);
        $b = &self::node($blocks, $loc['addr']);
        $bn = (string) $b['blockName'];
        if (!self::is_acf_block($bn)) self::fail('block ' . $bn . ' at path ' . $loc['path'] . ' is not a registered ACF block — use update_post for non-ACF content');
        if (!function_exists('acf_get_field')) self::fail('ACF not active');
        if (self::uses_post_meta($bn)) self::fail('block ' . $bn . ' at path ' . $loc['path'] . ' keeps its field values in post meta (use_post_meta), not in post_content — edit them with acf_update on the post');

        $defs = self::block_field_defs($bn);
        $old = is_array($b['attrs']['data'] ?? null) ? $b['attrs']['data'] : [];
        $tree = self::unflatten(array_values($defs), $old, '');
        $errors = [];
        $touched = [];
        self::apply_values($defs, $tree, $set, !empty($args['replace']), $errors, $touched);
        if ($errors) self::fail('Nothing was written: invalid input for ' . $bn . ' at path ' . $loc['path'] . ' (see list_block_fields).', $errors);

        $data = self::rebuild($defs, $old, $tree, array_keys($touched), $bn);
        $repaired = self::repair_refs($data); // heal <clone>_<field> refs written by versions before 2.4.0
        if (!isset($b['attrs']) || !is_array($b['attrs'])) $b['attrs'] = [];
        $b['attrs']['data'] = $data;

        $diff = self::diff_keys($old, $data);
        $out = ['path' => $loc['path'], 'blockName' => $bn, 'fields_updated' => array_keys($touched),
                'keys_changed' => array_keys($diff['changed']), 'keys_removed' => array_keys($diff['removed'])];
        if ($repaired) $out['refs_repaired'] = $repaired;
        $off = array_intersect_key(self::disabled_rows($data), $touched); // merged rows keep their disabled state
        if ($off) $out['disabled_rows'] = $off;
        if ($dry) $out['changes'] = (object) ($diff['changed'] + $diff['removed']);
        return $out;
    }

    static function op_insert(array &$blocks, $args, $dry) {
        $spec = $args['block'] ?? null;
        $pos = self::parse_position($args['position'] ?? null);
        $errors = [];
        $defaults = [];
        $bb = self::build_block($spec, 'block', $errors, $defaults);
        if ($errors || !$bb) self::fail('Nothing was written: invalid block spec.', $errors);

        $parent_path = $args['parent_path'] ?? null;
        if ($parent_path !== null && $parent_path !== '') {
            $paddr = self::addr_from_path($blocks, $parent_path);
            $parent = &self::node($blocks, $paddr);
            self::assert_container($parent, (string) $parent_path);
            $p = self::insert_inner($parent, $pos, $bb);
            $path = self::path_of($blocks, $paddr) . '.' . $p;
        } else {
            $p = self::insert_top($blocks, $pos, $bb);
            $path = (string) $p;
        }
        $out = ['inserted' => $bb['blockName'], 'path' => $path];
        if ($defaults) $out['defaults_applied'] = $defaults;
        if ($dry) $out['block'] = self::describe($bb, $path, true);
        return $out;
    }

    static function op_move(array &$blocks, $args, $dry) {
        $from = self::int_arg($args['from'] ?? null, 'from');
        $to = self::int_arg($args['to'] ?? null, 'to');
        $parent_path = $args['parent_path'] ?? null;
        if ($parent_path !== null && $parent_path !== '') {
            $paddr = self::addr_from_path($blocks, $parent_path);
            $parent = &self::node($blocks, $paddr);
            $n = count($parent['innerBlocks'] ?? []);
            if ($from >= $n || $to >= $n) self::fail('from/to out of range: block ' . $parent_path . ' has ' . $n . ' inner block(s) (0..' . ($n - 1) . ')');
            $item = array_values($parent['innerBlocks'])[$from];
            if ($from !== $to) { // from == to: nothing moves (and the separators stay as they are)
                self::remove_inner($parent, $from);
                self::insert_inner($parent, $to, $item);
            }
            $where = self::path_of($blocks, $paddr) . '.';
        } else {
            $raw = self::content_raw_indices($blocks);
            $n = count($raw);
            if ($from >= $n || $to >= $n) self::fail('from/to out of range (0..' . ($n - 1) . ')');
            $item = $blocks[$raw[$from]];
            if ($from !== $to) {
                self::remove_top($blocks, $raw[$from]);
                self::insert_top($blocks, $to, $item);
            }
            $where = '';
        }
        $out = ['blockName' => $item['blockName'], 'moved_from' => $from, 'to' => $to, 'path' => $where . $to];
        if ($dry) $out['outline'] = self::outline($blocks);
        return $out;
    }

    static function op_remove(array &$blocks, $args, $dry) {
        $loc = self::locate($blocks, $args['locator'] ?? null);
        $addr = $loc['addr'];
        $bn = self::node($blocks, $addr)['blockName'];
        if (count($addr) === 1) {
            self::remove_top($blocks, $addr[0]);
        } else {
            $parent = &self::node($blocks, array_slice($addr, 0, -1));
            self::remove_inner($parent, $addr[count($addr) - 1]);
        }
        $out = ['removed' => $bn, 'path' => $loc['path']];
        if ($dry) $out['outline'] = self::outline($blocks);
        return $out;
    }

    // ── FIELD VALUES: validate, merge, flatten ────────────────────────────

    /**
     * Apply $set (field name or flat path => value) to the nested $tree of a block.
     * Problems go to $errors with their path; $touched collects the top-level names changed.
     * Row indexes in flat paths always refer to the block as it was before the call: row removals
     * (tiles_2 => null) are applied last, deepest lists first and highest index first, and a key that
     * addresses something inside a removed row or inside a list/field another key replaces or clears
     * is refused as a conflict (nothing is written).
     */
    static function apply_values(array $defs, array &$tree, array $set, $replace, array &$errors, array &$touched) {
        $addrs = [];    // key => [address segments, 'removes'|'resets'|null]
        $removals = []; // resolved steps of row removals, applied after every other key
        foreach ($set as $key => $val) {
            $key = (string) $key;
            if (isset($defs[$key])) {
                $f = $defs[$key];
                $touched[$key] = true;
                $addrs[$key] = [[$key], ($val === null || self::is_list_field($f)) ? 'resets' : null];
                if ($val === null) { unset($tree[$key]); continue; }
                if ($replace) {
                    $tree[$key] = self::merge_value($f, null, $val, $key, $errors, false);
                } else {
                    $base = array_key_exists($key, $tree) ? $tree[$key] : self::default_value($f);
                    $tree[$key] = self::merge_value($f, $base, $val, $key, $errors, true);
                }
                continue;
            }
            $r = self::resolve_flat(array_values($defs), $key, $tree);
            if ($r === null) {
                $errors[] = $key . ': unknown field (neither a field name of this block nor a flat path such as tiles_0_title; valid names: ' . implode(', ', array_keys($defs)) . ')';
                continue;
            }
            if (isset($r['error'])) { $errors[] = $key . ': ' . $r['error']; continue; }
            $touched[$r[0]['name']] = true;
            $last = $r[count($r) - 1];
            $seg = array_map(function ($st) { return isset($st['row']) ? '#' . $st['row'] : $st['name']; }, $r);
            if (isset($last['row']) && $val === null) { // removing a row: deferred so later indexes do not shift
                $addrs[$key] = [$seg, 'removes'];
                $removals[] = $r;
                continue;
            }
            $addrs[$key] = [$seg, (!isset($last['row']) && ($val === null || self::is_list_field($last['field']))) ? 'resets' : null];
            self::apply_flat($tree, $r, $val, $key, $errors, $replace);
        }
        foreach ($addrs as $a => $pa) {
            if ($pa[1] === null) continue;
            foreach ($addrs as $b => $pb) {
                if ($a === $b || count($pb[0]) <= count($pa[0]) || array_slice($pb[0], 0, count($pa[0])) !== $pa[0]) continue;
                $errors[] = $b . ': conflicts with ' . $a . ' in the same set (' . $a . ($pa[1] === 'removes' ? ' removes that row' : ' replaces or clears the whole ' . $pa[0][count($pa[0]) - 1])
                    . '); row indexes refer to the block before the call — send the two in separate calls';
            }
        }
        if ($errors) return;
        usort($removals, function ($x, $y) {
            return (count($y) - count($x)) ?: ($y[count($y) - 1]['row'] - $x[count($x) - 1]['row']);
        });
        foreach ($removals as $steps) self::remove_row($tree, $steps);
    }

    static function is_list_field($f) {
        return in_array($f['type'] ?? '', ['repeater', 'flexible_content'], true);
    }

    /** Remove the row a resolved flat path (ending in a row step) points at. */
    static function remove_row(array &$tree, array $steps) {
        $node = &$tree;
        $last = count($steps) - 1;
        for ($s = 0; $s < $last; $s++) {
            $st = $steps[$s];
            $k = isset($st['row']) ? $st['row'] : $st['name'];
            if (!isset($node[$k]) || !is_array($node[$k])) return;
            $node = &$node[$k];
        }
        if (is_array($node) && array_key_exists($steps[$last]['row'], $node)) array_splice($node, $steps[$last]['row'], 1);
    }

    /**
     * Validate $in against field $f and merge it into $base (the current nested value).
     * $seed: missing sub-values of NEW rows/groups get field defaults (merge mode); false = replace.
     */
    static function merge_value($f, $base, $in, $path, array &$errors, $seed) {
        $type = $f['type'] ?? '';
        if ($type === 'group' || $type === 'clone') {
            if (!self::is_object_like($in)) { $errors[] = $path . ': ' . $type . ' expects an object {sub_field: value, ...}, got ' . self::show($in); return $base; }
            $b = self::is_object_like($base) ? $base : ($seed ? self::default_value($f) : []);
            return self::merge_object(self::sub_index($f['sub_fields'] ?? []), $b, $in, $path, $errors, $seed);
        }
        if ($type === 'repeater') {
            if (!is_array($in) || !array_is_list($in)) {
                $errors[] = $path . ': repeater expects a LIST of row objects [{sub_field: value}, ...], got ' . self::show($in) . ' (block_get shows rows flattened as ' . $path . '_0_<sub>; nested:true shows the list shape; to change one value use the flat path)';
                return $base;
            }
            $cur = (is_array($base) && array_is_list($base)) ? $base : [];
            $subs = self::sub_index($f['sub_fields'] ?? []);
            $rows = [];
            foreach ($in as $i => $row) {
                $rp = $path . '[' . $i . ']';
                if (!self::is_object_like($row)) { $errors[] = $rp . ': a row must be an object {sub_field: value, ...}, got ' . self::show($row); continue; }
                $rb = (isset($cur[$i]) && is_array($cur[$i])) ? $cur[$i] : ($seed ? self::default_object($subs) : []);
                $rows[] = self::merge_object($subs, $rb, $row, $rp, $errors, $seed);
            }
            return $rows;
        }
        if ($type === 'flexible_content') {
            if (!is_array($in) || !array_is_list($in)) { $errors[] = $path . ': flexible content expects a LIST of rows [{acf_fc_layout: name, sub_field: value}, ...], got ' . self::show($in); return $base; }
            $cur = (is_array($base) && array_is_list($base)) ? $base : [];
            $rows = [];
            foreach ($in as $i => $row) {
                $r = self::merge_flex_row($f, $cur[$i] ?? null, $row, $path . '[' . $i . ']', $errors, $seed);
                if ($r !== null) $rows[] = $r;
            }
            return $rows;
        }
        return self::normalize_leaf($f, $in, $path, $errors);
    }

    /** One flexible row: merges into the current row only when acf_fc_layout is unchanged. */
    static function merge_flex_row($f, $cur, $row, $rp, array &$errors, $seed) {
        if (!self::is_object_like($row)) { $errors[] = $rp . ': a row must be an object {acf_fc_layout: name, ...}, got ' . self::show($row); return null; }
        $layouts = [];
        foreach ((array) ($f['layouts'] ?? []) as $L) $layouts[$L['name'] ?? ''] = $L;
        $cur_ln = is_array($cur) ? ($cur['acf_fc_layout'] ?? null) : null;
        $ln = array_key_exists('acf_fc_layout', $row) ? $row['acf_fc_layout'] : $cur_ln;
        if (!is_string($ln) || $ln === '' || !isset($layouts[$ln])) {
            $errors[] = $rp . '.acf_fc_layout: ' . ($ln === null ? 'required' : 'unknown layout ' . self::show($ln)) . ' (layouts: ' . implode(', ', array_keys($layouts)) . ')';
            return null;
        }
        $subs = self::sub_index($layouts[$ln]['sub_fields'] ?? []);
        $out = ($cur_ln === $ln && is_array($cur)) ? $cur : ($seed ? self::default_object($subs) : []);
        $out = ['acf_fc_layout' => $ln] + $out;
        if (array_key_exists('acf_fc_layout_disabled', $row)) {
            $v = $row['acf_fc_layout_disabled'];
            if (!in_array($v, [true, false, null, 0, 1, '0', '1', ''], true)) $errors[] = $rp . '.acf_fc_layout_disabled: expects true/false';
            elseif ($v === true || $v === 1 || $v === '1') $out['acf_fc_layout_disabled'] = true;
            else unset($out['acf_fc_layout_disabled']);
        }
        if (array_key_exists('acf_fc_layout_custom_label', $row)) {
            $v = $row['acf_fc_layout_custom_label'];
            if ($v !== null && !is_string($v)) $errors[] = $rp . '.acf_fc_layout_custom_label: expects a string';
            elseif ($v === null || trim($v) === '') unset($out['acf_fc_layout_custom_label']);
            else $out['acf_fc_layout_custom_label'] = sanitize_text_field($v);
        }
        $rest = array_diff_key($row, ['acf_fc_layout' => 1, 'acf_fc_layout_disabled' => 1, 'acf_fc_layout_custom_label' => 1]);
        return self::merge_object($subs, $out, $rest, $rp, $errors, $seed);
    }

    /** Merge an object of sub-values: unknown keys are errors, null deletes, the rest merges recursively. */
    static function merge_object(array $subs, $base, array $in, $path, array &$errors, $seed) {
        $out = is_array($base) ? $base : [];
        foreach ($in as $k => $v) {
            $k = (string) $k;
            $kp = $path . '.' . $k;
            if (!isset($subs[$k])) {
                $errors[] = $kp . ': unknown sub-field (valid: ' . implode(', ', array_keys($subs)) . ')';
                continue;
            }
            if ($v === null) { unset($out[$k]); continue; }
            $sb = array_key_exists($k, $out) ? $out[$k] : ($seed ? self::default_value($subs[$k]) : null);
            $out[$k] = self::merge_value($subs[$k], $sb, $v, $kp, $errors, $seed);
        }
        return $out;
    }

    /**
     * Resolve a flat path (tiles_0_title, bg_video_mp4, fc_1_text) against the schema and the
     * current nested tree. Returns the steps [{field, name} | {row}], ['error' => msg] when it
     * clearly targets something that does not exist, or null when nothing matches.
     */
    static function resolve_flat(array $fields, $rest, $node) {
        $subs = self::sub_index($fields);
        if (isset($subs[$rest])) return [['field' => $subs[$rest], 'name' => $rest]];
        $names = array_keys($subs);
        usort($names, function ($a, $b) { return strlen($b) - strlen($a); }); // longest name first
        foreach ($names as $name) {
            $f = $subs[$name];
            $type = $f['type'] ?? '';
            $child = (is_array($node) && isset($node[$name]) && is_array($node[$name])) ? $node[$name] : [];
            if ($type === 'clone') {
                // a display-group clone stores its sub-values beside it, so flat keys omit the clone name
                $r = self::resolve_flat($f['sub_fields'] ?? [], $rest, $child);
                if ($r !== null) return isset($r['error']) ? $r : array_merge([['field' => $f, 'name' => $name]], $r);
                continue;
            }
            if (strpos($rest, $name . '_') !== 0) continue;
            $tail = substr($rest, strlen($name) + 1);
            if ($type === 'group') {
                $r = self::resolve_flat($f['sub_fields'] ?? [], $tail, $child);
                if ($r !== null) return isset($r['error']) ? $r : array_merge([['field' => $f, 'name' => $name]], $r);
            } elseif ($type === 'repeater' || $type === 'flexible_content') {
                if (!preg_match('/^(\d+)(?:_(.+))?$/', $tail, $m)) continue;
                $i = (int) $m[1];
                if (!isset($child[$i]) || !is_array($child[$i])) {
                    return ['error' => $name . ' has no row ' . $i . ' (it has ' . count($child) . ') — set the whole ' . $name . ' list to add rows'];
                }
                $steps = [['field' => $f, 'name' => $name], ['row' => $i]];
                if (!isset($m[2]) || $m[2] === '') return $steps;
                $sf = $type === 'repeater' ? ($f['sub_fields'] ?? []) : self::layout_fields($f, $child[$i]['acf_fc_layout'] ?? null);
                $r = self::resolve_flat($sf, $m[2], $child[$i]);
                if ($r === null) return ['error' => 'row ' . $i . ' of ' . $name . ' has no sub-field matching "' . $m[2] . '"'];
                return isset($r['error']) ? $r : array_merge($steps, $r);
            }
        }
        return null;
    }

    /** Apply one resolved flat path to the nested tree (merge semantics unless $replace). */
    static function apply_flat(array &$tree, array $steps, $val, $key, array &$errors, $replace) {
        $node = &$tree;
        $last = count($steps) - 1;
        for ($s = 0; $s < $last; $s++) {
            $st = $steps[$s];
            if (isset($st['row'])) { $node = &$node[$st['row']]; continue; }
            $n = $st['name'];
            if (!isset($node[$n]) || !is_array($node[$n])) {
                $node[$n] = in_array($st['field']['type'] ?? '', ['group', 'clone'], true) && !$replace ? self::default_value($st['field']) : [];
            }
            $node = &$node[$n];
        }
        $st = $steps[$last];
        if (isset($st['row'])) { // whole row: null removes it (apply_values defers that), an object merges into it
            $f = $steps[$last - 1]['field'];
            $i = $st['row'];
            if ($val === null) { array_splice($node, $i, 1); return; }
            if (($f['type'] ?? '') === 'flexible_content') {
                $r = self::merge_flex_row($f, $replace ? null : $node[$i], $val, $key, $errors, !$replace);
                if ($r !== null) $node[$i] = $r;
            } elseif (!self::is_object_like($val)) {
                $errors[] = $key . ': a row must be an object {sub_field: value, ...}, got ' . self::show($val);
            } else {
                $subs = self::sub_index($f['sub_fields'] ?? []);
                $node[$i] = self::merge_object($subs, $replace ? [] : $node[$i], $val, $key, $errors, !$replace);
            }
            return;
        }
        $f = $st['field'];
        $n = $st['name'];
        if ($val === null) { unset($node[$n]); return; }
        if ($replace) {
            $node[$n] = self::merge_value($f, null, $val, $key, $errors, false);
        } else {
            $base = array_key_exists($n, $node) ? $node[$n] : self::default_value($f);
            $node[$n] = self::merge_value($f, $base, $val, $key, $errors, true);
        }
    }

    /** Default nested value of a field, as the editor would post it for an untouched field. */
    static function default_value($f) {
        $type = $f['type'] ?? '';
        if ($type === 'repeater' || $type === 'flexible_content') return [];
        if ($type === 'group' || $type === 'clone') return self::default_object(self::sub_index($f['sub_fields'] ?? []));
        $d = (array_key_exists('default_value', $f) && $f['default_value'] !== null) ? $f['default_value'] : '';
        if (is_int($d) || is_float($d)) $d = (string) $d; // the editor posts form values as strings
        if (in_array($type, ['radio', 'button_group', 'select'], true) && !($type === 'select' && !empty($f['multiple']))) {
            // one value: a select posts its first selected option; with no valid default and no
            // allow_null the editor pre-selects (and so saves) the FIRST choice
            if (is_array($d)) $d = $d ? (string) reset($d) : '';
            $choices = array_map('strval', array_keys((array) ($f['choices'] ?? [])));
            $custom = ($type === 'radio' && !empty($f['other_choice']) && $d !== '') || ($type === 'select' && !empty($f['create_options']));
            if ($choices && empty($f['allow_null']) && !$custom && !in_array((string) $d, $choices, true)) $d = $choices[0];
        }
        $ignored = [];
        return self::normalize_leaf($f, $d, '', $ignored);
    }

    static function default_object(array $subs) {
        $out = [];
        foreach ($subs as $name => $sf) $out[$name] = self::default_value($sf);
        return $out;
    }

    /**
     * Validate and normalize a scalar-ish field value to ACF's storage format.
     * On a problem: append "<path>: message" to $errors and return the input unchanged.
     */
    static function normalize_leaf($f, $v, $path, array &$errors) {
        $type = $f['type'] ?? '';
        $p = ($path === '' ? ($f['name'] ?? '') : $path) . ' (' . $type . ')';
        switch ($type) {
            case 'text': case 'textarea': case 'wysiwyg': case 'password': case 'oembed':
                if (is_string($v)) return $v;
                if (is_int($v) || is_float($v)) return (string) $v;
                $errors[] = $p . ': expects a string, got ' . self::show($v);
                return $v;

            case 'color_picker':
                if (is_string($v) || self::is_object_like($v)) return $v;
                $errors[] = $p . ': expects a color string, got ' . self::show($v);
                return $v;

            case 'email':
                if ($v === '') return '';
                $flags = defined('FILTER_FLAG_EMAIL_UNICODE') ? FILTER_FLAG_EMAIL_UNICODE : 0;
                if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL, $flags) !== false) return $v;
                $errors[] = $p . ': not a valid email address: ' . self::show($v);
                return $v;

            case 'url':
                if ($v === '') return '';
                if (is_string($v) && (strpos($v, '://') !== false || strpos($v, '//') === 0)) return $v;
                $errors[] = $p . ': expects a URL (with scheme, or protocol-relative //), got ' . self::show($v);
                return $v;

            case 'number': case 'range':
                if ($v === '') return '';
                $n = is_string($v) ? str_replace(',', '', trim($v)) : $v;
                if (is_bool($n) || !is_numeric($n)) { $errors[] = $p . ': expects a number, got ' . self::show($v); return $v; }
                if (is_numeric($f['min'] ?? '') && (float) $n < (float) $f['min']) { $errors[] = $p . ': must be >= ' . $f['min'] . ', got ' . $n; return $v; }
                if (is_numeric($f['max'] ?? '') && (float) $n > (float) $f['max']) { $errors[] = $p . ': must be <= ' . $f['max'] . ', got ' . $n; return $v; }
                return $n;

            case 'true_false':
                if ($v === true || $v === 1 || $v === '1') return '1';
                if ($v === false || $v === 0 || $v === '0' || $v === '') return '0';
                $errors[] = $p . ': expects true/false (or 1/0), got ' . self::show($v);
                return $v;

            case 'image': case 'file':
                return self::norm_attachment($v, $p, $errors);

            case 'gallery':
                if ($v === '' || $v === [] || $v === false) return '';
                if (!is_array($v) || !array_is_list($v)) { $errors[] = $p . ': expects a list of attachment IDs, got ' . self::show($v); return $v; }
                $ids = [];
                foreach ($v as $i => $item) {
                    $id = self::norm_attachment($item, $p . '[' . $i . ']', $errors);
                    if ($id !== '') $ids[] = (string) $id;
                }
                return $ids ?: '';

            case 'link':
                if ($v === '' || $v === []) return '';
                if (!self::is_object_like($v)) { $errors[] = $p . ': expects an object {url, title?, target?} or "" to clear, got ' . self::show($v); return $v; }
                $bad = array_diff(array_keys($v), ['title', 'url', 'target']);
                if ($bad) { $errors[] = $p . ': unknown key(s) ' . implode(', ', $bad) . ' (a link is {url, title?, target?})'; return $v; }
                foreach (['title', 'url', 'target'] as $lk) {
                    if (isset($v[$lk]) && !is_string($v[$lk])) { $errors[] = $p . ': ' . $lk . ' must be a string'; return $v; }
                }
                if (($v['url'] ?? '') === '') return ''; // ACF stores a link without url as ""
                return ['title' => (string) ($v['title'] ?? ''), 'url' => $v['url'], 'target' => (string) ($v['target'] ?? '')];

            case 'select': case 'checkbox': case 'radio': case 'button_group':
                return self::norm_choice($f, $v, $p, $errors);

            case 'post_object': case 'relationship': case 'page_link': case 'taxonomy': case 'user':
                return self::norm_refs($f, $v, $p, $errors);

            case 'date_picker':
                if ($v === '') return '';
                if (is_string($v) || is_int($v)) {
                    $s = trim((string) $v);
                    if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $s, $m) && (strlen($s) === 8 || strlen($s) === 10) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                        return $m[1] . $m[2] . $m[3];
                    }
                }
                $errors[] = $p . ': expects a date as Ymd (20261231) or Y-m-d (2026-12-31), got ' . self::show($v);
                return $v;

            case 'date_time_picker':
                if ($v === '') return '';
                if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim($v), $m)
                    && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) ($m[6] ?? 0) < 60) {
                    return sprintf('%s-%s-%s %s:%s:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], (int) ($m[6] ?? 0));
                }
                $errors[] = $p . ': expects "Y-m-d H:i:s" or "Y-m-d H:i" (e.g. 2026-12-31 18:30), got ' . self::show($v);
                return $v;

            case 'time_picker':
                if ($v === '') return '';
                if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($v), $m) && (int) $m[1] < 24 && (int) $m[2] < 60 && (int) ($m[3] ?? 0) < 60) {
                    return sprintf('%02d:%s:%02d', (int) $m[1], $m[2], (int) ($m[3] ?? 0));
                }
                $errors[] = $p . ': expects "H:i:s" or "H:i" (e.g. 18:30), got ' . self::show($v);
                return $v;

            case 'google_map':
                if ($v === '' || $v === false || $v === []) return false; // what ACF stores for an empty map
                if (is_string($v)) {
                    $d = json_decode($v, true);
                    if (!is_array($d)) { $errors[] = $p . ': expects an object {lat, lng, address?, zoom?, ...} or its JSON string, got ' . self::show($v); return $v; }
                    $v = $d;
                }
                if (!self::is_object_like($v)) { $errors[] = $p . ': expects an object {lat, lng, address?, zoom?, ...}, got ' . self::show($v); return $v; }
                return $v;
        }
        if (in_array($type, self::ACF_TYPES, true)) return $v; // other ACF types (icon_picker, …): as given
        // Custom field type (e.g. the theme's button_type / icon_select): normalize through its own
        // update_value(), which acf_field hooks as "acf/update_value/type=<type>" — exactly what ACF
        // runs when the editor saves the block (such types need not be in ACF's type registry).
        $hook = 'acf/update_value/type=' . $type;
        if ($type !== '' && has_filter($hook)) {
            try {
                return apply_filters($hook, $v, 'block_simple_mcp', $f, $v);
            } catch (\Throwable $e) {
                $errors[] = $p . ': rejected by the field type (' . $e->getMessage() . ')';
            }
        }
        return $v;
    }

    /** image/file: an existing attachment ID (ACF stores the integer); empty clears (""). */
    static function norm_attachment($v, $p, array &$errors) {
        if ($v === '' || $v === false || $v === 0 || $v === '0') return '';
        $id = 0;
        if (is_int($v)) $id = $v;
        elseif (is_string($v) && ctype_digit(trim($v))) $id = (int) $v;
        elseif (is_array($v) && (isset($v['ID']) || isset($v['id'])) && is_numeric($v['ID'] ?? $v['id'])) $id = (int) ($v['ID'] ?? $v['id']);
        else {
            $errors[] = $p . ': expects an attachment ID, got ' . self::show($v) . ' (upload with upload_media and use its attachment_id)';
            return $v;
        }
        $a = $id > 0 ? get_post($id) : null;
        if (!$a || $a->post_type !== 'attachment') {
            $errors[] = $p . ': attachment #' . $id . ' does not exist';
            return $v;
        }
        return $id;
    }

    /** select/checkbox/radio/button_group: value(s) must be among the choices unless the field allows custom values. */
    static function norm_choice($f, $v, $p, array &$errors) {
        $type = $f['type'] ?? '';
        $multi = $type === 'checkbox' || ($type === 'select' && !empty($f['multiple']));
        $custom = ($type === 'select' && !empty($f['create_options'])) || ($type === 'checkbox' && !empty($f['allow_custom']))
            || ($type === 'radio' && !empty($f['other_choice']));
        $choices = array_map('strval', array_keys((array) ($f['choices'] ?? [])));
        $check = function ($x, $xp) use ($custom, $choices, &$errors) {
            if (!is_string($x) && !is_int($x) && !is_float($x)) { $errors[] = $xp . ': expects a choice value, got ' . self::show($x); return null; }
            $s = (string) $x;
            if (!$custom && !in_array($s, $choices, true)) { $errors[] = $xp . ': "' . $s . '" is not a valid choice (' . implode(', ', $choices) . ')'; return null; }
            return $s;
        };
        if ($multi) {
            if ($v === '' || $v === []) return '';
            if (is_array($v) && !array_is_list($v)) { $errors[] = $p . ': expects a list of choice values, got ' . self::show($v); return $v; }
            $out = [];
            foreach ((is_array($v) ? $v : [$v]) as $i => $x) {
                $s = $check($x, $p . '[' . $i . ']');
                if ($s !== null) $out[] = $s;
            }
            return $out;
        }
        if ($v === '') return '';
        if (is_array($v)) { $errors[] = $p . ': expects ONE choice value, got ' . self::show($v); return $v; }
        $s = $check($v, $p);
        return $s === null ? $v : $s;
    }

    /** post_object/relationship/page_link/taxonomy/user: existing IDs, stored the way the field's update_value stores them. */
    static function norm_refs($f, $v, $p, array &$errors) {
        $type = $f['type'] ?? '';
        if ($type === 'relationship') $multi = true;
        elseif ($type === 'taxonomy') $multi = in_array($f['field_type'] ?? 'checkbox', ['checkbox', 'multi_select'], true);
        else $multi = !empty($f['multiple']);
        if ($v === '' || $v === [] || $v === false || $v === 0 || $v === '0') return '';
        if ($multi) {
            if (is_array($v) && !array_is_list($v) && !isset($v['ID']) && !isset($v['id']) && !isset($v['term_id'])) {
                $errors[] = $p . ': expects a list of IDs, got ' . self::show($v);
                return $v;
            }
            $items = (is_array($v) && array_is_list($v)) ? $v : [$v];
        } else {
            if (is_array($v) && array_is_list($v)) { $errors[] = $p . ': expects ONE ID, got a list'; return $v; }
            $items = [$v];
        }
        $out = [];
        foreach ($items as $i => $x) {
            $xp = $multi ? $p . '[' . $i . ']' : $p;
            if (is_array($x)) $x = $x['ID'] ?? ($x['id'] ?? ($x['term_id'] ?? null));
            if (is_string($x) && ctype_digit(trim($x))) $x = (int) $x;
            if (!is_int($x) || $x <= 0) {
                if ($type === 'page_link' && is_string($x) && $x !== '') { $out[] = $x; continue; } // archive URL
                $errors[] = $xp . ': expects an ID, got ' . self::show($x);
                continue;
            }
            if ($type === 'user') {
                if (!get_userdata($x)) { $errors[] = $xp . ': user #' . $x . ' does not exist'; continue; }
            } elseif ($type === 'taxonomy') {
                if (!(get_term($x, (string) ($f['taxonomy'] ?? '')) instanceof WP_Term)) { $errors[] = $xp . ': term #' . $x . ' does not exist in taxonomy ' . ($f['taxonomy'] ?? '?'); continue; }
            } else {
                $o = get_post($x);
                if (!$o || $o->post_type === 'revision') { $errors[] = $xp . ': post #' . $x . ' does not exist'; continue; }
                $allowed = array_filter((array) ($f['post_type'] ?? []));
                if ($allowed && !in_array($o->post_type, $allowed, true)) { $errors[] = $xp . ': post #' . $x . ' is a ' . $o->post_type . '; this field accepts ' . implode(', ', $allowed); continue; }
            }
            $out[] = $x;
        }
        if ($multi) return array_map('strval', $out);
        return $out ? $out[0] : $v;
    }

    /**
     * Inverse of flatten(): the nested view of stored block data (only values that exist).
     * Flexible rows carry acf_fc_layout plus acf_fc_layout_disabled/_custom_label from the layout meta.
     */
    static function unflatten(array $schema, array $data, $prefix) {
        $out = [];
        foreach ($schema as $f) {
            $type = $f['type'] ?? '';
            $name = $f['name'] ?? '';
            if ($name === '' || in_array($type, self::NON_STORABLE, true)) continue;
            $fk = $prefix === '' ? $name : $prefix . '_' . $name;
            if ($type === 'repeater') {
                if (!array_key_exists($fk, $data)) continue;
                $cnt = is_numeric($data[$fk]) ? (int) $data[$fk] : 0;
                $rows = [];
                for ($i = 0; $i < $cnt; $i++) $rows[] = self::unflatten($f['sub_fields'] ?? [], $data, $fk . '_' . $i);
                $out[$name] = $rows;
            } elseif ($type === 'group') {
                $sub = self::unflatten($f['sub_fields'] ?? [], $data, $fk);
                if ($sub || array_key_exists($fk, $data)) $out[$name] = $sub;
            } elseif ($type === 'clone') {
                if (empty($f['sub_fields'])) continue;
                $sub = self::unflatten($f['sub_fields'], $data, $prefix);
                if ($sub || array_key_exists($fk, $data)) $out[$name] = $sub;
            } elseif ($type === 'flexible_content') {
                if (!array_key_exists($fk, $data)) continue;
                $meta = $data['_' . $fk . '_layout_meta'] ?? [];
                $disabled = array_map('intval', (array) ($meta['disabled'] ?? []));
                $renamed = (array) ($meta['renamed'] ?? []);
                $rows = [];
                foreach (array_values(is_array($data[$fk]) ? $data[$fk] : []) as $i => $ln) {
                    $row = ['acf_fc_layout' => $ln];
                    if (in_array($i, $disabled, true)) $row['acf_fc_layout_disabled'] = true;
                    if (!empty($renamed[$i]) && is_string($renamed[$i])) $row['acf_fc_layout_custom_label'] = $renamed[$i];
                    $rows[] = $row + self::unflatten(self::layout_fields($f, $ln), $data, $fk . '_' . $i);
                }
                $out[$name] = $rows;
            } elseif (array_key_exists($fk, $data)) {
                $out[$name] = $data[$fk];
            }
        }
        return $out;
    }

    /**
     * Flatten validated nested {name: value} into ACF block-data format (with _name => field_key
     * mirrors), recursively, matching what ACF's own editor save (acf_setup_meta capture) stores:
     * - repeater: row count (int; "" when empty) + {fk}_{i}_{sub} values;
     * - group / display-group clone: an EMPTY parent value + its reference, sub-values beside it
     *   (get_fields() walks the stored keys, so without the parent the whole group is skipped);
     * - seamless clone: ACF expands it into its sub-fields; at the top level of a block ACF also
     *   stores the clone's own parent entry ("hdr":"" + "_hdr":clone key), which get_fields()
     *   needs to return the clone as a group (see seamless_parent());
     * - flexible content: list of layout names ("" when empty) + "_{fk}_layout_meta"
     *   {disabled:[row…], renamed:{row: label}} — ACF hides disabled rows from the front end;
     * - references use field_ref(): the field's real key, never the in-memory <clone>_<field> key
     *   (ACF itself writes that temporary key for seamless clones inside repeaters/groups; its
     *   loader never reads nested references, and the real key also resolves).
     * Only fields present in $values are written.
     */
    static function flatten($schema, $values, $prefix, &$flat, $level = 0) {
        foreach ($schema as $f) {
            $type = $f['type'] ?? '';
            $name = $f['name'] ?? '';
            if ($name === '' || in_array($type, self::NON_STORABLE, true)) continue;
            if ($type === 'clone' && empty($f['sub_fields'])) continue;
            if (!is_array($values) || !array_key_exists($name, $values)) continue;
            $val = $values[$name];
            $fk = $prefix === '' ? $name : $prefix . '_' . $name;
            $key = self::field_ref($f);
            if ($level === 0 && !empty($f['_clone'])) self::seamless_parent($f, $flat);

            if ($type === 'repeater') {
                $rows = is_array($val) ? array_values($val) : [];
                $flat[$fk] = $rows ? count($rows) : '';
                $flat['_' . $fk] = $key;
                foreach ($rows as $i => $row) {
                    self::flatten($f['sub_fields'] ?? [], is_array($row) ? $row : [], $fk . '_' . $i, $flat, $level + 1);
                }
            } elseif ($type === 'group') {
                if (!is_array($val) || !$val) continue; // ACF stores nothing for an empty group
                $flat[$fk] = '';
                $flat['_' . $fk] = $key;
                self::flatten($f['sub_fields'] ?? [], $val, $fk, $flat, $level + 1);
            } elseif ($type === 'clone') {
                // display "group" clone: sub-fields stored FLAT beside it under their own names
                // (prefixed only when prefix_name is on, which the sub-field names already carry)
                if (!is_array($val) || !$val) continue;
                $flat[$fk] = '';
                $flat['_' . $fk] = $key;
                self::flatten($f['sub_fields'], $val, $prefix, $flat, $level + 1);
            } elseif ($type === 'flexible_content') {
                $rows = is_array($val) ? array_values($val) : [];
                $names = [];
                $disabled = [];
                $renamed = [];
                foreach ($rows as $i => $row) {
                    $ln = is_array($row) ? ($row['acf_fc_layout'] ?? null) : null;
                    $names[] = $ln;
                    if (!empty($row['acf_fc_layout_disabled'])) $disabled[] = $i;
                    if (!empty($row['acf_fc_layout_custom_label'])) $renamed[$i] = (string) $row['acf_fc_layout_custom_label'];
                    self::flatten(self::layout_fields($f, $ln), (array) $row, $fk . '_' . $i, $flat, $level + 1);
                }
                $flat['_' . $fk . '_layout_meta'] = ['disabled' => $disabled, 'renamed' => $renamed];
                $flat[$fk] = $names ?: '';
                $flat['_' . $fk] = $key;
            } else {
                $flat[$fk] = $val;
                $flat['_' . $fk] = $key;
            }
        }
    }

    /**
     * Parent entry of a seamless clone at the top level of the block ("hdr":"" + "_hdr":clone key),
     * written once, exactly like ACF's clone update_value() does on an editor save.
     */
    static function seamless_parent($f, &$flat) {
        if (!function_exists('acf_get_field')) return;
        $clone = acf_get_field($f['_clone']);
        if (!$clone || ($clone['type'] ?? '') !== 'clone' || ($clone['display'] ?? '') !== 'seamless') return;
        $cn = (string) ($clone['name'] ?? '');
        if ($cn === '' || array_key_exists($cn, $flat)) return;
        $flat[$cn] = '';
        $flat['_' . $cn] = $clone['key'];
    }

    /**
     * Replace the stored keys of the touched top-level fields with the flattened new values,
     * keeping every other key (and the original key order) untouched.
     */
    static function rebuild(array $defs, array $old, array $tree, array $touched, $bn) {
        $owner = [];   // old key => field name being rewritten
        $fresh = [];   // field name => new flat keys
        foreach ($touched as $name) {
            $f = $defs[$name];
            $keys = [];
            self::collect_field_keys($f, $old, '', $keys);
            if (in_array($f['type'] ?? '', ['repeater', 'flexible_content'], true)) self::sweep_orphans($f, $name, $old, $defs, $keys);
            foreach ($keys as $k) if (array_key_exists($k, $old)) $owner[$k] = $name;
            $nf = [];
            if (array_key_exists($name, $tree)) self::flatten([$f], [$name => $tree[$name]], '', $nf);
            self::assert_refs_resolve($nf, $bn); // never save a value ACF would silently drop
            $fresh[$name] = $nf;
        }
        $data = [];
        foreach ($old as $k => $v) {
            if (!isset($owner[$k])) { $data[$k] = $v; continue; }
            $name = $owner[$k];
            if (isset($fresh[$name])) { foreach ($fresh[$name] as $nk => $nv) $data[$nk] = $nv; unset($fresh[$name]); }
        }
        foreach ($fresh as $nf) foreach ($nf as $nk => $nv) $data[$nk] = $nv;
        return $data;
    }

    /**
     * Orphan rows of a repeater/flexible field (rows beyond a stale count): only keys
     * "{fk}_{n}_{rest}" whose rest is a KNOWN sub-field of that field (or starts with one + "_"),
     * never a key that is itself a top-level field — so a sibling like price_2024_note survives.
     */
    static function sweep_orphans($f, $fk, array $data, array $defs, array &$keys) {
        $subs = [];
        if (($f['type'] ?? '') === 'repeater') {
            $subs = array_keys(self::sub_index($f['sub_fields'] ?? []));
        } else {
            foreach ((array) ($f['layouts'] ?? []) as $L) $subs = array_merge($subs, array_keys(self::sub_index($L['sub_fields'] ?? [])));
        }
        if (!$subs) return;
        foreach (array_keys($data) as $k) {
            $k = (string) $k;
            $kk = ($k !== '' && $k[0] === '_') ? substr($k, 1) : $k;
            if (isset($defs[$kk]) || !preg_match('/^' . preg_quote($fk, '/') . '_\d+_(.+)$/', $kk, $m)) continue;
            foreach ($subs as $s) {
                if ($m[1] === $s || strpos($m[1], $s . '_') === 0) { $keys[] = $k; break; }
            }
        }
    }

    /** Collect the exact existing flat keys that belong to a field (for precise replace). */
    static function collect_field_keys($f, $data, $prefix, &$keys) {
        $type = $f['type'] ?? '';
        $name = $f['name'] ?? '';
        if ($name === '' || in_array($type, self::NON_STORABLE, true)) return;
        $fk = $prefix === '' ? $name : $prefix . '_' . $name;
        $keys[] = $fk;
        $keys[] = '_' . $fk;
        if ($type === 'repeater') {
            $cnt = (isset($data[$fk]) && is_numeric($data[$fk])) ? (int) $data[$fk] : 0;
            for ($i = 0; $i < $cnt; $i++) {
                foreach ($f['sub_fields'] ?? [] as $sf) self::collect_field_keys($sf, $data, $fk . '_' . $i, $keys);
            }
        } elseif ($type === 'group') {
            foreach ($f['sub_fields'] ?? [] as $sf) self::collect_field_keys($sf, $data, $fk, $keys);
        } elseif ($type === 'clone') {
            foreach ($f['sub_fields'] ?? [] as $sf) self::collect_field_keys($sf, $data, $prefix, $keys);
        } elseif ($type === 'flexible_content') {
            $keys[] = '_' . $fk . '_layout_meta';
            $rows = (isset($data[$fk]) && is_array($data[$fk])) ? array_values($data[$fk]) : [];
            foreach ($rows as $i => $ln) {
                foreach (self::layout_fields($f, $ln) as $sf) self::collect_field_keys($sf, $data, $fk . '_' . $i, $keys);
            }
        }
    }

    /**
     * The field key to store in the "_name" reference.
     *
     * acf_get_fields() expands a SEAMLESS clone into the cloned fields and gives each one a
     * temporary key "<clone key>_<field key>" (so sub clones load the right values); the real key
     * is kept in __key, and ACF restores it via acf/prepare_field when it renders the input, so
     * the editor posts and saves the real key. That
     * temporary key does not resolve through acf_get_field(), so storing it made ACF drop the
     * value on the front end while the write still reported content_verified. Always store __key.
     */
    static function field_ref($f) {
        return (string) (!empty($f['__key']) ? $f['__key'] : ($f['key'] ?? ''));
    }

    /** Throw if any "_name" reference in freshly built block data does not resolve in ACF. */
    static function assert_refs_resolve($flat, $bn) {
        if (!function_exists('acf_get_field')) return;
        $bad = [];
        foreach ($flat as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '_' && is_string($v) && $v !== '' && !acf_get_field($v)) $bad[] = "$k=$v";
        }
        if ($bad) {
            throw new \RuntimeException('Refusing to write ' . $bn . ': ACF cannot resolve field reference(s) ' . implode(', ', $bad) . ' — the value would be silently dropped on the front end.');
        }
    }

    /**
     * Flexible-content rows an editor disabled ({flat field key: [row, …]}, from
     * "_{key}_layout_meta"): ACF hides them on the front end.
     */
    static function disabled_rows(array $data) {
        $out = [];
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if ($k === '' || $k[0] !== '_' || substr($k, -12) !== '_layout_meta' || !is_array($v) || empty($v['disabled'])) continue;
            $out[substr($k, 1, -12)] = array_values(array_map('intval', (array) $v['disabled']));
        }
        return $out;
    }

    /** "_name" references in stored data that ACF cannot resolve. */
    static function broken_refs(array $data) {
        if (!function_exists('acf_get_field')) return [];
        $bad = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '_' && is_string($v) && strpos($v, 'field_') === 0 && !acf_get_field($v)) $bad[] = $k . '=' . $v;
        }
        return $bad;
    }

    /**
     * Repair "<clone key>_<field key>" references that earlier versions of block_update wrote for
     * seamless-clone fields: when the stored reference does not resolve, try every "_field_"
     * boundary and take the first tail that resolves to a field whose name matches the stored
     * name (or its last segment for repeater rows: buttons_0_link → link). Returns the list of
     * repaired "_name" keys; healthy references are never touched (cheap gate first).
     */
    static function repair_refs(&$data) {
        if (!function_exists('acf_get_field') || !is_array($data)) return [];
        $fixed = [];
        foreach ($data as $k => $v) {
            if (!is_string($k) || $k === '' || $k[0] !== '_' || !is_string($v)) continue;
            if (strpos($v, 'field_') !== 0 || strpos($v, '_field_', 6) === false || acf_get_field($v)) continue;
            $name = substr($k, 1);
            $off = 6;
            while (($pos = strpos($v, '_field_', $off)) !== false) {
                $cand = substr($v, $pos + 1);
                $f = acf_get_field($cand);
                if ($f && !empty($f['name']) && ($name === $f['name'] || substr($name, -(strlen($f['name']) + 1)) === '_' . $f['name'])) {
                    $data[$k] = $cand;
                    $fixed[] = $k;
                    break;
                }
                $off = $pos + 1;
            }
        }
        return $fixed;
    }

    /**
     * Key-level diff of two flat data arrays ('_' references left out, flexible layout meta kept).
     * Values compare with same(): a stored "2703" re-normalized to 2703 is not a change, "7" → "007" is.
     */
    static function diff_keys(array $old, array $new) {
        $shown = function ($k) { $k = (string) $k; return $k !== '' && ($k[0] !== '_' || substr($k, -12) === '_layout_meta'); };
        $changed = [];
        $removed = [];
        foreach ($new as $k => $v) {
            if (!$shown($k)) continue;
            if (!array_key_exists($k, $old)) $changed[$k] = ['old' => null, 'new' => $v];
            elseif (!self::same($old[$k], $v)) $changed[$k] = ['old' => $old[$k], 'new' => $v];
        }
        foreach ($old as $k => $v) {
            if ($shown($k) && !array_key_exists($k, $new)) $removed[$k] = ['old' => $v, 'new' => null];
        }
        return ['changed' => $changed, 'removed' => $removed];
    }

    // ── BUILDING BLOCKS FROM SPECS ────────────────────────────────────────

    /**
     * Build a block array from a spec {blockName, data?, attrs?, innerBlocks?, html?, mode?}.
     * Problems go to $errors (with $where paths); $defaults[$where] lists the fields that got
     * default values. Returns null when the block cannot be built.
     */
    static function build_block($spec, $where = 'block', &$errors = null, &$defaults = null) {
        if (!is_array($errors)) $errors = [];
        if (!is_array($defaults)) $defaults = [];
        if (!self::is_object_like($spec) || !$spec) { $errors[] = $where . ': must be an object {blockName, data?, ...}'; return null; }
        $unknown = array_diff(array_keys($spec), ['blockName', 'data', 'attrs', 'innerBlocks', 'html', 'innerHTML', 'align', 'mode']);
        if ($unknown) $errors[] = $where . ': unknown key(s) ' . implode(', ', $unknown) . ' (allowed: blockName, data, attrs, innerBlocks, html, mode, align)';
        $bn = self::resolve_block_name($spec['blockName'] ?? null, $where, $errors);
        if ($bn === null) return null;
        $isACF = self::is_acf_block($bn);
        $html = $spec['innerHTML'] ?? ($spec['html'] ?? '');
        if (!is_string($html)) { $errors[] = $where . '.html: must be a string'; $html = ''; }
        $xattrs = $spec['attrs'] ?? [];
        if (!self::is_object_like($xattrs)) { $errors[] = $where . '.attrs: must be an object'; $xattrs = []; }

        if ($isACF) {
            if (!function_exists('acf_get_fields')) { $errors[] = $where . ': ACF not active'; return null; }
            $data = $spec['data'] ?? [];
            if (!self::is_object_like($data)) { $errors[] = $where . '.data: must be an object {field_name: value, ...}'; $data = []; }
            if ($html !== '') $errors[] = $where . ': html/innerHTML is only for non-ACF blocks (ACF blocks render from data)';
            $mode = $spec['mode'] ?? 'preview';
            if (!in_array($mode, ['preview', 'edit', 'auto'], true)) $errors[] = $where . '.mode: must be preview, edit or auto';
            if (self::uses_post_meta($bn) && $data) {
                $errors[] = $where . ': ' . $bn . ' keeps its field values in post meta (use_post_meta) — insert it without data, then fill the fields with acf_update on the post';
            }
            $reserved = array_intersect(array_keys($xattrs), ['name', 'data', 'mode']);
            if ($reserved) $errors[] = $where . '.attrs: ' . implode(', ', $reserved) . ' are managed by the server (use blockName/data/mode)';

            $defs = self::block_field_defs($bn);
            // Seed every storable field with its default (what the editor posts for an untouched
            // block), then the block type's registered default data, then the spec itself.
            $tree = self::default_object(self::sub_index(array_values($defs)));
            $ignored = [];
            foreach (self::type_defaults($bn, $defs) as $name => $v) {
                $tree[$name] = self::merge_value($defs[$name], $tree[$name] ?? null, $v, $name, $ignored, true);
            }
            $errs = [];
            $touched = [];
            self::apply_values($defs, $tree, $data, false, $errs, $touched);
            foreach ($errs as $e) $errors[] = $where . '.data.' . $e;
            $applied = array_values(array_diff(array_keys($tree), array_keys($touched)));
            if ($applied) $defaults[$where] = $applied;
            $flat = [];
            self::flatten(array_values($defs), $tree, '', $flat);
            try {
                self::assert_refs_resolve($flat, $bn);
            } catch (\RuntimeException $e) {
                $errors[] = $where . ': ' . $e->getMessage();
            }
            $attrs = ['name' => $bn, 'data' => $flat];
            foreach ($xattrs as $k => $v) if (!in_array($k, ['name', 'data', 'mode'], true)) $attrs[$k] = $v;
            if (isset($spec['align'])) $attrs['align'] = $spec['align'];
            $attrs['mode'] = $mode;
        } else {
            if (array_key_exists('data', $spec)) $errors[] = $where . '.data: only ACF blocks take data — use attrs/html for ' . $bn;
            if (array_key_exists('mode', $spec)) $errors[] = $where . '.mode: only ACF blocks have a mode';
            $attrs = $xattrs;
            if (isset($spec['align'])) $attrs['align'] = $spec['align'];
        }

        $inner = [];
        if (isset($spec['innerBlocks']) && $spec['innerBlocks'] !== []) {
            if (!is_array($spec['innerBlocks']) || !array_is_list($spec['innerBlocks'])) {
                $errors[] = $where . '.innerBlocks: must be a list of block specs';
            } else {
                $why = self::inner_refusal($bn);
                if ($why) $errors[] = $where . '.innerBlocks: ' . $bn . ' ' . $why;
                foreach ($spec['innerBlocks'] as $i => $ib) {
                    $bb = self::build_block($ib, $where . '.innerBlocks[' . $i . ']', $errors, $defaults);
                    if ($bb) $inner[] = $bb;
                }
            }
        }

        $block = ['blockName' => $bn, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => $html !== '' ? [$html] : []];
        // innerContent needs one null placeholder per inner block, else serialize_block() drops the children
        foreach ($inner as $i => $ib) self::insert_inner($block, $i, $ib);
        return $block;
    }

    /** "acf/x" / "core/x" as given; a bare name resolves to a registered ACF block first, then core. */
    static function resolve_block_name($name, $where, array &$errors) {
        if (!is_string($name) || trim($name) === '') { $errors[] = $where . '.blockName: required'; return null; }
        $name = trim($name);
        $registry = class_exists('WP_Block_Type_Registry') ? WP_Block_Type_Registry::get_instance() : null;
        $registered = function ($n) use ($registry) { return $registry && $registry->is_registered($n); };
        if (strpos($name, '/') === false) {
            if (self::is_acf_block('acf/' . $name)) return 'acf/' . $name;
            if ($registered('core/' . $name)) return 'core/' . $name;
        } elseif (self::is_acf_block($name) || $registered($name)) {
            return $name;
        }
        $errors[] = $where . '.blockName: unknown block type "' . $name . '" (not a registered ACF or core block). ACF blocks: ' . implode(', ', self::acf_block_names());
        return null;
    }

    // ── BLOCK TREE: locate, insert, remove ────────────────────────────────

    /**
     * Resolve a locator to ['addr' => [raw top-level index, inner index, …], 'path' => "2.0"].
     * Every criterion given must match the same block; throws with a precise reason otherwise.
     */
    static function locate($blocks, $locator) {
        if (!self::is_object_like($locator) || !$locator) self::fail('locator must be an object with path, index, anchor and/or blockName (from block_get)');
        $bad = array_diff(array_keys($locator), ['path', 'index', 'anchor', 'blockName', 'nth']);
        if ($bad) self::fail('unknown locator key(s): ' . implode(', ', $bad) . ' (use path, index, anchor, blockName, nth)');
        $show = wp_json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = self::content_raw_indices($blocks);
        $addr = null;

        if (array_key_exists('path', $locator)) $addr = self::addr_from_path($blocks, $locator['path']);
        if (array_key_exists('index', $locator)) {
            $i = self::int_arg($locator['index'], 'locator.index');
            if (!isset($raw[$i])) self::fail('locator.index ' . $i . ' out of range: there are ' . count($raw) . ' top-level blocks');
            if ($addr !== null && $addr !== [$raw[$i]]) self::fail('locator ' . $show . ': path and index point to different blocks');
            $addr = [$raw[$i]];
        }
        if (array_key_exists('anchor', $locator)) {
            $a = $locator['anchor'];
            if (!is_string($a) || trim($a) === '') self::fail('locator.anchor must be a non-empty string (most blocks have no anchor — use path or index)');
            $a = trim($a);
            $hits = [];
            foreach (self::walk($blocks) as $w) {
                if (self::anchor_of($w['block']) === $a) $hits[] = $w;
            }
            if (!$hits) self::fail('no block has anchor "' . $a . '"');
            if (count($hits) > 1) self::fail('anchor "' . $a . '" is carried by ' . count($hits) . ' blocks (paths ' . implode(', ', array_column($hits, 'path')) . ') — use path');
            if ($addr !== null && $addr !== $hits[0]['addr']) self::fail('locator ' . $show . ': anchor "' . $a . '" is on block ' . $hits[0]['path'] . ', not on the block given by path/index');
            $addr = $hits[0]['addr'];
        }
        if (array_key_exists('blockName', $locator)) {
            $want = $locator['blockName'];
            if (!is_string($want) || trim($want) === '') self::fail('locator.blockName must be a non-empty string');
            $want = trim($want);
            if (strpos($want, '/') === false) $want = self::is_acf_block('acf/' . $want) || !self::is_core_block('core/' . $want) ? 'acf/' . $want : 'core/' . $want;
            if ($addr !== null) {
                if (array_key_exists('nth', $locator)) self::fail('locator.nth is only valid with blockName alone');
                $got = self::node($blocks, $addr)['blockName'];
                if ($got !== $want) self::fail('locator ' . $show . ': the block at ' . self::path_of($blocks, $addr) . ' is ' . $got . ', not ' . $want);
            } else {
                $nth = array_key_exists('nth', $locator) ? self::int_arg($locator['nth'], 'locator.nth') : 0;
                $hits = [];
                foreach ($raw as $r) if ($blocks[$r]['blockName'] === $want) $hits[] = $r;
                if (!isset($hits[$nth])) self::fail('locator ' . $show . ': ' . count($hits) . ' top-level ' . $want . ' block(s), no nth=' . $nth);
                $addr = [$hits[$nth]];
            }
        } elseif (array_key_exists('nth', $locator)) {
            self::fail('locator.nth is only valid with blockName');
        }
        if ($addr === null) self::fail('locator needs path, index, anchor or blockName');
        return ['addr' => $addr, 'path' => self::path_of($blocks, $addr)];
    }

    /** Address for a path "2" / "2.0.1" (top-level ordinal, then inner indices). */
    static function addr_from_path($blocks, $path) {
        $s = is_int($path) ? (string) $path : (is_string($path) ? trim($path) : '');
        if (!preg_match('~^\d+(?:[./]\d+)*$~', $s)) self::fail('path must look like "2" or "2.0.1" (from block_get), got ' . self::show($path));
        $parts = array_map('intval', preg_split('~[./]~', $s));
        $raw = self::content_raw_indices($blocks);
        if (!isset($raw[$parts[0]])) self::fail('no top-level block ' . $parts[0] . ' (there are ' . count($raw) . ')');
        $addr = [$raw[$parts[0]]];
        $b = $blocks[$raw[$parts[0]]];
        $at = (string) $parts[0];
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $inner = array_values($b['innerBlocks'] ?? []);
            if (!isset($inner[$parts[$i]])) self::fail('block ' . $at . ' has no inner block ' . $parts[$i] . ' (it has ' . count($inner) . ')');
            $addr[] = $parts[$i];
            $b = $inner[$parts[$i]];
            $at .= '.' . $parts[$i];
        }
        return $addr;
    }

    static function path_of($blocks, array $addr) {
        $ord = array_search($addr[0], self::content_raw_indices($blocks), true);
        return implode('.', array_merge([(int) $ord], array_slice($addr, 1)));
    }

    /** Reference to the block at an address. */
    static function &node(array &$blocks, array $addr) {
        $ref = &$blocks[$addr[0]];
        for ($i = 1, $n = count($addr); $i < $n; $i++) {
            $ref = &$ref['innerBlocks'][$addr[$i]];
        }
        return $ref;
    }

    /** Every named block at every depth: [{addr, path, block}]. */
    static function walk($blocks, $addr = [], $path = '') {
        $out = [];
        $ord = 0;
        foreach ($blocks as $k => $b) {
            if (empty($b['blockName'])) continue;
            $a = array_merge($addr, [$k]);
            $p = $path === '' ? (string) $ord : $path . '.' . $k;
            $out[] = ['addr' => $a, 'path' => $p, 'block' => $b];
            if (!empty($b['innerBlocks'])) $out = array_merge($out, self::walk($b['innerBlocks'], $a, $p));
            $ord++;
        }
        return $out;
    }

    /** Compact "path blockName" list of a block tree (dry-run previews). */
    static function outline($blocks) {
        return array_map(function ($w) { return $w['path'] . ' ' . $w['block']['blockName']; }, self::walk($blocks));
    }

    /** Raw indices (positions in the full parse_blocks array) of the non-empty blocks. */
    static function content_raw_indices($blocks) {
        $raw = [];
        foreach ($blocks as $i => $b) {
            if (!empty($b['blockName'])) $raw[] = $i;
        }
        return $raw;
    }

    static function content_blocks($post_content) {
        return array_values(array_filter(parse_blocks($post_content), fn($b) => !empty($b['blockName'])));
    }

    /** The blank freeform segment the editor writes between top-level blocks. */
    static function separator() {
        return ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => ["\n\n"]];
    }

    static function is_blank_freeform($b) {
        return is_array($b) && empty($b['blockName']) && empty($b['innerBlocks']) && trim((string) ($b['innerHTML'] ?? '')) === '';
    }

    /** Insert at the top level (position = int ordinal or 'end'); returns the new ordinal. */
    static function insert_top(array &$blocks, $pos, array $bb) {
        $raw = self::content_raw_indices($blocks);
        $n = count($raw);
        $p = ($pos === 'end' || $pos >= $n) ? $n : (int) $pos;
        if ($p < $n) {
            array_splice($blocks, $raw[$p], 0, [$bb, self::separator()]);
        } elseif ($pos !== 'end' && $p === 0 && $blocks) {
            array_unshift($blocks, $bb, self::separator()); // "start" of a body with classic content only
        } else {
            if ($blocks && !self::is_blank_freeform($blocks[count($blocks) - 1])) $blocks[] = self::separator();
            $blocks[] = $bb;
        }
        return $p;
    }

    /** Remove the top-level entry at a raw index plus one adjacent blank separator. */
    static function remove_top(array &$blocks, $raw_index) {
        array_splice($blocks, $raw_index, 1);
        if ($raw_index > 0 && self::is_blank_freeform($blocks[$raw_index - 1] ?? null)) array_splice($blocks, $raw_index - 1, 1);
        elseif (self::is_blank_freeform($blocks[$raw_index] ?? null)) array_splice($blocks, $raw_index, 1);
    }

    /**
     * Insert into a parent's innerBlocks at position (int or 'end') keeping innerContent's null
     * placeholders (one per inner block, in order) consistent; returns the new inner index.
     */
    static function insert_inner(array &$parent, $pos, array $bb) {
        $inner = array_values($parent['innerBlocks'] ?? []);
        $n = count($inner);
        $p = ($pos === 'end' || $pos >= $n) ? $n : (int) $pos;
        array_splice($inner, $p, 0, [$bb]);
        $ic = array_values((array) ($parent['innerContent'] ?? []));
        $nulls = array_keys($ic, null, true);
        if ($nulls) {
            if ($p < count($nulls)) array_splice($ic, $nulls[$p], 0, [null, "\n\n"]);
            else array_splice($ic, end($nulls) + 1, 0, ["\n\n", null]);
        } else {
            // first child: put it inside the wrapper element when there is one (<div …></div>)
            $html = implode('', $ic);
            if (trim($html) === '') $ic = [null];
            elseif (preg_match('~^(.*)(</[a-zA-Z][a-zA-Z0-9-]*>\s*)$~s', $html, $m)) $ic = [$m[1], null, $m[2]];
            else $ic = [$html, null];
        }
        $parent['innerBlocks'] = $inner;
        $parent['innerContent'] = $ic;
        $parent['innerHTML'] = implode('', array_filter($ic, 'is_string'));
        return $p;
    }

    /** Remove inner block $i with its placeholder and one blank separator chunk between blocks. */
    static function remove_inner(array &$parent, $i) {
        $inner = array_values($parent['innerBlocks'] ?? []);
        array_splice($inner, $i, 1);
        $ic = array_values((array) ($parent['innerContent'] ?? []));
        $nulls = array_keys($ic, null, true);
        if (isset($nulls[$i])) {
            $at = $nulls[$i];
            array_splice($ic, $at, 1);
            $blank = function ($c) { return is_string($c) && trim($c) === ''; };
            if ($at >= 2 && $blank($ic[$at - 1]) && $ic[$at - 2] === null) array_splice($ic, $at - 1, 1);
            elseif (isset($ic[$at]) && $blank($ic[$at]) && array_key_exists($at + 1, $ic) && $ic[$at + 1] === null) array_splice($ic, $at, 1);
        }
        $parent['innerBlocks'] = $inner;
        $parent['innerContent'] = $ic;
        $parent['innerHTML'] = implode('', array_filter($ic, 'is_string'));
    }

    static function assert_container($b, $path) {
        $bn = (string) ($b['blockName'] ?? '');
        $why = self::inner_refusal($bn);
        if ($why) self::fail('block ' . $path . ' (' . $bn . ') ' . $why);
    }

    /**
     * Why a block cannot hold inner blocks, or null when it can (or we cannot tell): ACF blocks
     * need jsx support; core blocks whose markup has no InnerBlocks slot are refused.
     */
    static function inner_refusal($bn) {
        if (self::is_acf_block($bn) && !self::accepts_inner($bn)) return 'does not accept inner blocks (no jsx/InnerBlocks support)';
        if (in_array($bn, self::CORE_LEAVES, true)) return 'does not accept inner blocks (its markup has no InnerBlocks slot)';
        return null;
    }

    /** Top-level paths whose block differs between two trees (post-save filter report). */
    static function diff_paths($a, $b) {
        $na = array_values(array_filter($a, function ($x) { return !empty($x['blockName']); }));
        $nb = array_values(array_filter($b, function ($x) { return !empty($x['blockName']); }));
        $out = [];
        for ($i = 0, $n = max(count($na), count($nb)); $i < $n; $i++) {
            if (!self::same($na[$i] ?? null, $nb[$i] ?? null)) $out[] = (string) $i;
        }
        return $out;
    }

    /**
     * Value equality for block trees and stored data. Strings compare exactly (PHP's loose ==
     * would call "7" and "007", or "1e3" and "1000", equal and the edit would be skipped as
     * unchanged); a number equals its own string form (a stored "2703" re-normalized to 2703);
     * arrays compare key by key (key order ignored); anything else strictly.
     */
    static function same($a, $b) {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || count($a) !== count($b)) return false;
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::same($v, $b[$k])) return false;
            }
            return true;
        }
        $na = is_int($a) || is_float($a);
        $nb = is_int($b) || is_float($b);
        if ($na && $nb) return $a == $b;
        if ($na && is_string($b)) return (string) $a === $b;
        if ($nb && is_string($a)) return (string) $b === $a;
        return $a === $b;
    }

    // ── SMALL HELPERS ─────────────────────────────────────────────────────

    /** ACF block? acf_has_block_type() when ACF is active (block.json blocks may use any namespace). */
    static function is_acf_block($name) {
        if (!is_string($name) || $name === '') return false;
        if (function_exists('acf_has_block_type')) return acf_has_block_type($name);
        return strpos($name, 'acf/') === 0;
    }

    static function is_core_block($name) {
        return class_exists('WP_Block_Type_Registry') && WP_Block_Type_Registry::get_instance()->is_registered($name);
    }

    static function acf_block_names() {
        return function_exists('acf_get_block_types') ? array_keys(acf_get_block_types()) : [];
    }

    static function uses_post_meta($bn) {
        return function_exists('acf_block_uses_post_meta') && self::is_acf_block($bn) && acf_block_uses_post_meta(['name' => $bn]);
    }

    static function accepts_inner($bn) {
        $bt = function_exists('acf_get_block_type') ? acf_get_block_type($bn) : null;
        return is_array($bt) && !empty($bt['supports']['jsx']);
    }

    static function anchor_of($b) {
        $attrs = is_array($b['attrs'] ?? null) ? $b['attrs'] : [];
        foreach ([$attrs['anchor'] ?? null, $attrs['data']['anchor'] ?? null] as $a) {
            if (is_string($a) && trim($a) !== '') return trim($a);
        }
        return null;
    }

    /** Storable fields by name (seamless clones are already expanded by acf_get_fields). */
    static function sub_index($fields) {
        $out = [];
        foreach ((array) $fields as $f) {
            $name = $f['name'] ?? '';
            $type = $f['type'] ?? '';
            if ($name === '' || in_array($type, self::NON_STORABLE, true)) continue;
            if ($type === 'clone' && empty($f['sub_fields'])) continue;
            $out[$name] = $f;
        }
        return $out;
    }

    static function layout_fields($f, $layout_name) {
        foreach ((array) ($f['layouts'] ?? []) as $L) {
            if (($L['name'] ?? '') === $layout_name) return $L['sub_fields'] ?? [];
        }
        return [];
    }

    /**
     * Default data the block type registers (acf_register_block_type 'data'), mapped to
     * top-level field names: the theme registers it by field KEY (responsive_options etc.).
     */
    static function type_defaults($bn, array $defs) {
        $bt = function_exists('acf_get_block_type') ? acf_get_block_type($bn) : null;
        $out = [];
        foreach ((is_array($bt) && is_array($bt['data'] ?? null)) ? $bt['data'] : [] as $k => $v) {
            $k = (string) $k;
            if (isset($defs[$k])) { $out[$k] = $v; continue; }
            foreach ($defs as $name => $f) {
                if ($k === self::field_ref($f) || $k === ($f['key'] ?? '')) { $out[$name] = $v; break; }
            }
        }
        return $out;
    }

    static function is_object_like($v) {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    static function int_arg($v, $name) {
        if (is_int($v) && $v >= 0) return $v;
        if (is_string($v) && $v !== '' && ctype_digit($v)) return (int) $v;
        self::fail($name . ' must be an integer >= 0, got ' . self::show($v));
    }

    /** position: integer >= 0, "start" or "end" (case-insensitive); default end. */
    static function parse_position($pos) {
        if ($pos === null) return 'end';
        if (is_int($pos) && $pos >= 0) return $pos;
        if (is_string($pos)) {
            $s = strtolower(trim($pos));
            if ($s === 'end') return 'end';
            if ($s === 'start') return 0;
            if ($s !== '' && ctype_digit($s)) return (int) $s;
        }
        self::fail('position must be an integer >= 0, "start" or "end", got ' . self::show($pos));
    }

    static function show($v) {
        $s = wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($s)) return gettype($v);
        return strlen($s) > 80 ? Simple_MCP_Tools::mb_cut($s, 80) . '…' : $s;
    }

    static function block_groups($bn) {
        $groups = acf_get_field_groups(['block' => $bn]);
        if (!empty($groups)) return $groups;
        return array_values(array_filter(acf_get_field_groups(), function ($g) use ($bn) {
            foreach ((array) ($g['location'] ?? []) as $or) {
                foreach ((array) $or as $rule) {
                    if (($rule['param'] ?? '') === 'block' && ($rule['value'] ?? '') === $bn) return true;
                }
            }
            return false;
        }));
    }

    /** Raw ACF field defs for a block, indexed by top-level field name. */
    static function block_field_defs($bn) {
        if (strpos($bn, '/') === false) $bn = 'acf/' . $bn;
        if (!function_exists('acf_get_field_groups')) return [];
        $defs = [];
        foreach (self::block_groups($bn) as $g) {
            foreach (acf_get_fields($g['key']) as $f) {
                if (($f['name'] ?? '') === '') continue;
                $defs[$f['name']] = $f;
            }
        }
        return $defs;
    }
}
