<?php
/**
 * Block toolset — safe reading/editing of ACF-in-Gutenberg block content.
 *
 * In ACF+Timber themes almost all page content lives INLINE in the post_content
 * block-delimiter JSON (flattened ACF data with a _name=>field_key mirror, \uXXXX-escaped
 * HTML), NOT in post meta. acf_update() cannot reach it. These tools run parse_blocks()/
 * serialize_blocks() server-side and resolve field_keys from the ACF registry at runtime,
 * so an AI never hand-assembles delimiter JSON. All writes go through the shared
 * Simple_MCP_Tools::save_post_content (auto-revision + wp_slash + byte-verify).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Blocks {

    const LOCATOR = [
        'type' => 'object', 'additionalProperties' => false,
        'description' => 'How to target a top-level block. Priority: index > anchor > blockName(+nth).',
        'properties' => [
            'index'     => ['type' => 'integer', 'description' => 'ordinal among top-level blocks (from block_get)'],
            'anchor'    => ['type' => 'string', 'description' => 'the block anchor if set'],
            'blockName' => ['type' => 'string', 'description' => 'e.g. acf/main-first-screen'],
            'nth'       => ['type' => 'integer', 'description' => '0-based index among blockName matches (default 0)'],
        ],
    ];

    static function defs() {
        return [
            'block_get' => [
                'description' => 'Read a page/post body as a structured list of top-level Gutenberg blocks: index, blockName, anchor, innerBlocks count, and for ACF blocks the decoded field=>value data (flattened; repeater rows appear as buttons_0_button, buttons_1_button, ...). This is the ONLY reliable way to read ACF block field values remotely — they live inline in post_content, not in post meta. Always call this before block_update to see current values and choose a locator.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
                'callback' => [__CLASS__, 'block_get'],
            ],
            'list_block_fields' => [
                'description' => 'Return the ACF field schema (name, field_key, type, sub_fields, layouts, choices, default) for an ACF block, read from the ACF registry at runtime. Needed to know valid field names/types before block_update/block_replace, since remote clients cannot read theme acf-json. Includes the shared block-settings group (padding/margin/bg/anchor) whose keys differ per fork.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['block_name' => ['type' => 'string', 'description' => 'e.g. "acf/main-first-screen" or "main-first-screen"']],
                    'required' => ['block_name']],
                'callback' => [__CLASS__, 'list_block_fields'],
            ],
            'block_update' => [
                'description' => 'Safely edit one or more ACF fields of a single block instance in place. Give a locator (from block_get) and set:{field:value,...} using field NAMES (not keys). The server resolves field_keys, writes the _name=>field_key mirror, handles repeaters/groups/flexible via a recursive flattener, re-serializes with serialize_blocks, wp_slash-es and byte-verifies. This replaces fragile hand-editing of block-delimiter JSON. Values: scalars as-is; image/file = attachment ID; link = {title,url,target}; gallery = [ids]; repeater = [ {sub:val}, ... ]; group = {sub:val}; flexible = [ {acf_fc_layout:name, sub:val}, ... ].',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'locator' => self::LOCATOR,
                        'set'     => ['type' => 'object', 'description' => 'field name => new value'],
                    ],
                    'required' => ['post_id', 'locator', 'set']],
                'callback' => [__CLASS__, 'block_update'],
            ],
            'block_insert' => [
                'description' => 'Insert a new block built from a spec {blockName, data:{field:value,...}} at a position (integer index among top-level blocks, or "end"). Server builds valid ACF block data (flattener + field_key mirror) — no raw markup needed.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'  => ['type' => 'integer'],
                        'block'    => ['type' => 'object', 'description' => '{blockName, data:{...}, innerBlocks?:[...]}'],
                        'position' => ['description' => 'integer index or "end" (default end)'],
                    ],
                    'required' => ['post_id', 'block']],
                'callback' => [__CLASS__, 'block_insert'],
            ],
            'block_move' => [
                'description' => 'Reorder a top-level block from index "from" to index "to" (indices as reported by block_get).',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer'], 'from' => ['type' => 'integer'], 'to' => ['type' => 'integer']],
                    'required' => ['post_id', 'from', 'to']],
                'callback' => [__CLASS__, 'block_move'],
            ],
            'block_remove' => [
                'description' => 'Remove a single top-level block identified by a locator (from block_get). Auto-revision keeps a rollback point.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer'], 'locator' => self::LOCATOR],
                    'required' => ['post_id', 'locator']],
                'callback' => [__CLASS__, 'block_remove'],
            ],
            'block_replace' => [
                'description' => 'Replace the ENTIRE post body with a new list of blocks built from specs [{blockName, data:{...}, innerBlocks?}]. Use to compose a page from scratch. Destructive to existing body — auto-revision keeps a rollback point; prefer block_update/insert for edits.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer'], 'blocks' => ['type' => 'array', 'items' => ['type' => 'object']]],
                    'required' => ['post_id', 'blocks']],
                'callback' => [__CLASS__, 'block_replace'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    // ── READ ──────────────────────────────────────────────────────────────

    static function block_get($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        $blocks = parse_blocks($post->post_content);
        $out = [];
        $i = 0;
        foreach ($blocks as $b) {
            if (empty($b['blockName'])) continue;
            $out[] = self::describe($b, $i);
            $i++;
        }
        return self::ok(['post_id' => $post_id, 'post_type' => $post->post_type, 'block_count' => count($out), 'blocks' => $out]);
    }

    static function describe($b, $index) {
        $name  = $b['blockName'];
        $attrs = $b['attrs'] ?? [];
        $isACF = strpos($name, 'acf/') === 0;
        $e = [
            'index' => $index, 'blockName' => $name, 'isACF' => $isACF,
            'anchor' => $attrs['anchor'] ?? ($attrs['data']['anchor'] ?? null),
            'innerBlocks' => count($b['innerBlocks'] ?? []),
        ];
        if ($isACF && !empty($attrs['data']) && is_array($attrs['data'])) {
            $fields = [];
            foreach ($attrs['data'] as $k => $v) {
                if ($k === '' || $k[0] === '_') continue;
                $fields[$k] = $v;
            }
            $e['mode'] = $attrs['mode'] ?? null;
            $e['fields'] = $fields;
        } else {
            $e['html'] = mb_substr(trim(wp_strip_all_tags($b['innerHTML'] ?? '')), 0, 200);
        }
        return $e;
    }

    static function list_block_fields($args) {
        $bn = (string) ($args['block_name'] ?? '');
        if ($bn === '') return self::err('block_name required');
        if (strpos($bn, 'acf/') !== 0) $bn = 'acf/' . $bn;
        if (!function_exists('acf_get_field_groups')) return self::err('ACF not active');
        $groups = self::block_groups($bn);
        if (empty($groups)) return self::err('No ACF field groups located to block ' . $bn);
        $fields = [];
        $titles = [];
        foreach ($groups as $g) {
            $titles[] = $g['title'];
            $fields = array_merge($fields, self::map_fields(acf_get_fields($g['key'])));
        }
        return self::ok(['block_name' => $bn, 'groups' => $titles, 'fields' => $fields]);
    }

    static function map_fields($fields) {
        $skip = ['accordion', 'tab', 'message'];
        $out = [];
        foreach ((array) $fields as $f) {
            if (in_array($f['type'] ?? '', $skip, true)) continue;
            if (($f['name'] ?? '') === '') continue;
            $e = ['name' => $f['name'], 'key' => $f['key'], 'type' => $f['type']];
            if (!empty($f['label']))    $e['label']    = $f['label'];
            if (!empty($f['required'])) $e['required'] = true;
            if (isset($f['default_value']) && $f['default_value'] !== '' && $f['default_value'] !== null) $e['default'] = $f['default_value'];
            if (!empty($f['choices']) && is_array($f['choices'])) $e['choices'] = array_keys($f['choices']);
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

    static function block_update($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        $set = $args['set'] ?? null;
        if (!is_array($set) || !$set) return self::err('"set" object {field:value,...} required');

        $blocks = parse_blocks($post->post_content);
        $raw = self::locate($blocks, (array) ($args['locator'] ?? []));
        if ($raw < 0) return self::err('target block not found (locator: ' . wp_json_encode($args['locator'] ?? []) . ')');
        $bn = $blocks[$raw]['blockName'];
        if (strpos($bn, 'acf/') !== 0) return self::err('block ' . $bn . ' is not an ACF block — use update_post for non-ACF content');

        $defs = self::block_field_defs($bn);
        $data = $blocks[$raw]['attrs']['data'] ?? [];
        if (!is_array($data)) $data = [];

        $applied = [];
        $unknown = [];
        foreach ($set as $fname => $fval) {
            if (!isset($defs[$fname])) { $unknown[] = $fname; continue; }
            $old = [];
            self::collect_field_keys($defs[$fname], $data, '', $old); // exact existing keys of this field
            foreach ($old as $k) unset($data[$k]);
            // safety net for repeater/flex: sweep any orphan indexed rows (stale/non-numeric count)
            if (in_array($defs[$fname]['type'] ?? '', ['repeater', 'flexible_content'], true)) {
                foreach (array_keys($data) as $k) {
                    if (preg_match('/^_?' . preg_quote($fname, '/') . '_\d+(_|$)/', $k)) unset($data[$k]);
                }
            }
            $nf = [];
            self::flatten([$defs[$fname]], [$fname => $fval], '', $nf);
            foreach ($nf as $k => $v) $data[$k] = $v;
            $applied[] = $fname;
        }
        if ($unknown) return self::err('unknown field(s) on ' . $bn . ': ' . implode(', ', $unknown) . ' — use list_block_fields');

        $blocks[$raw]['attrs']['data'] = $data;
        $verified = Simple_MCP_Tools::save_post_content($post_id, serialize_blocks($blocks));
        if (is_wp_error($verified)) return self::err($verified->get_error_message());
        return self::ok(['post_id' => $post_id, 'blockName' => $bn, 'fields_updated' => $applied, 'content_verified' => $verified]);
    }

    static function block_insert($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        $spec = $args['block'] ?? null;
        if (!is_array($spec) || empty($spec['blockName'])) return self::err('"block" {blockName, data} required');
        $bb = self::build_block($spec);
        if (!$bb) return self::err('could not build block (unknown block type or invalid spec)');

        $blocks = parse_blocks($post->post_content); // full array: keep freeform/classic segments
        $pos = $args['position'] ?? 'end';
        if ($pos === 'end' || $pos === null) {
            $blocks[] = $bb;
        } else {
            $raw = self::content_raw_indices($blocks);
            $p = intval($pos);
            if ($p >= count($raw)) $blocks[] = $bb;
            else array_splice($blocks, $raw[$p], 0, [$bb]);
        }
        $verified = Simple_MCP_Tools::save_post_content($post_id, serialize_blocks($blocks));
        if (is_wp_error($verified)) return self::err($verified->get_error_message());
        return self::ok(['post_id' => $post_id, 'inserted' => $bb['blockName'], 'content_verified' => $verified]);
    }

    static function block_move($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        $from = intval($args['from'] ?? -1);
        $to = intval($args['to'] ?? -1);
        $blocks = parse_blocks($post->post_content); // full array: keep freeform/classic segments
        $raw = self::content_raw_indices($blocks);
        $n = count($raw);
        if ($from < 0 || $from >= $n || $to < 0 || $to >= $n) return self::err('from/to out of range (0..' . ($n - 1) . ')');
        $item = $blocks[$raw[$from]];
        array_splice($blocks, $raw[$from], 1);
        $raw2 = self::content_raw_indices($blocks);
        if ($to >= count($raw2)) $blocks[] = $item;
        else array_splice($blocks, $raw2[$to], 0, [$item]);
        $verified = Simple_MCP_Tools::save_post_content($post_id, serialize_blocks($blocks));
        if (is_wp_error($verified)) return self::err($verified->get_error_message());
        return self::ok(['post_id' => $post_id, 'moved_from' => $from, 'to' => $to, 'content_verified' => $verified]);
    }

    static function block_remove($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        $blocks = parse_blocks($post->post_content);
        $raw = self::locate($blocks, (array) ($args['locator'] ?? []));
        if ($raw < 0) return self::err('target block not found');
        $bn = $blocks[$raw]['blockName'];
        unset($blocks[$raw]);
        $verified = Simple_MCP_Tools::save_post_content($post_id, serialize_blocks(array_values($blocks)));
        if (is_wp_error($verified)) return self::err($verified->get_error_message());
        return self::ok(['post_id' => $post_id, 'removed' => $bn, 'content_verified' => $verified]);
    }

    static function block_replace($args) {
        $post_id = intval($args['post_id'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('post not found');
        $specs = $args['blocks'] ?? null;
        if (!is_array($specs)) return self::err('"blocks" array required');
        $content = [];
        foreach ($specs as $s) {
            $bb = self::build_block($s);
            if (!$bb) return self::err('bad block spec: ' . wp_json_encode($s));
            $content[] = $bb;
        }
        $verified = Simple_MCP_Tools::save_post_content($post_id, serialize_blocks($content));
        if (is_wp_error($verified)) return self::err($verified->get_error_message());
        return self::ok(['post_id' => $post_id, 'blocks_written' => count($content), 'content_verified' => $verified]);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────

    static function content_blocks($post_content) {
        return array_values(array_filter(parse_blocks($post_content), fn($b) => !empty($b['blockName'])));
    }

    /** Raw indices (positions in the full parse_blocks array) of the non-empty blocks. */
    static function content_raw_indices($blocks) {
        $raw = [];
        foreach ($blocks as $i => $b) {
            if (!empty($b['blockName'])) $raw[] = $i;
        }
        return $raw;
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
        if (strpos($bn, 'acf/') !== 0) $bn = 'acf/' . $bn;
        $defs = [];
        foreach (self::block_groups($bn) as $g) {
            foreach (acf_get_fields($g['key']) as $f) {
                if (($f['name'] ?? '') === '') continue;
                $defs[$f['name']] = $f;
            }
        }
        return $defs;
    }

    /**
     * Locate a top-level block; returns raw index in parse_blocks() array or -1.
     * ALL provided criteria (index, anchor, blockName) must match the SAME block — so a stale
     * locator whose index and anchor disagree resolves to nothing (caller errors) instead of
     * silently editing the wrong block. nth applies only when blockName alone is given.
     */
    static function locate($blocks, $locator) {
        $wantIndex  = array_key_exists('index', $locator) ? intval($locator['index']) : null;
        $wantAnchor = $locator['anchor'] ?? null;
        $wantName   = $locator['blockName'] ?? null;
        if ($wantName && strpos($wantName, '/') === false) $wantName = 'acf/' . $wantName;
        $nth = array_key_exists('nth', $locator) ? intval($locator['nth']) : 0;
        if ($wantIndex === null && $wantAnchor === null && $wantName === null) return -1;

        $ord = 0;
        $nameMatch = 0;
        foreach ($blocks as $raw => $b) {
            if (empty($b['blockName'])) continue;
            $ok = true;
            if ($wantIndex !== null && $ord !== $wantIndex) $ok = false;
            if ($ok && $wantAnchor !== null) {
                $a = $b['attrs']['anchor'] ?? ($b['attrs']['data']['anchor'] ?? null);
                if ($a !== $wantAnchor) $ok = false;
            }
            if ($ok && $wantName !== null && $b['blockName'] !== $wantName) $ok = false;
            if ($ok) {
                if ($wantIndex === null && $wantAnchor === null && $wantName !== null) {
                    if ($nameMatch === $nth) return $raw; // nth only for blockName-only locators
                    $nameMatch++;
                } else {
                    return $raw;
                }
            }
            $ord++;
        }
        return -1;
    }

    /** Build a block array from a friendly spec {blockName, data, innerBlocks?, html?}. Throws on invalid data. */
    static function build_block($spec) {
        $bn = (string) ($spec['blockName'] ?? '');
        if ($bn === '') return null;
        if (strpos($bn, '/') === false) $bn = 'acf/' . $bn;
        $isACF = strpos($bn, 'acf/') === 0;
        // validate the block type actually exists (else a contentless ghost block is written)
        $registered = class_exists('WP_Block_Type_Registry') && WP_Block_Type_Registry::get_instance()->is_registered($bn);
        if (!$registered && !($isACF && self::block_field_defs($bn))) return null;

        $attrs = ['name' => $bn, 'mode' => $spec['mode'] ?? 'preview'];
        if ($isACF) {
            $flat = [];
            self::flatten(array_values(self::block_field_defs($bn)), (array) ($spec['data'] ?? []), '', $flat);
            $attrs['data'] = $flat;
        } elseif (isset($spec['attrs']) && is_array($spec['attrs'])) {
            $attrs = array_merge($attrs, $spec['attrs']); // core blocks: pass through attrs
        }
        if (isset($spec['align'])) $attrs['align'] = $spec['align'];

        $inner = [];
        if (!empty($spec['innerBlocks']) && is_array($spec['innerBlocks'])) {
            foreach ($spec['innerBlocks'] as $ib) {
                $bb = self::build_block($ib);
                if ($bb) $inner[] = $bb;
            }
        }
        // innerHTML for non-ACF blocks (core/html, core/paragraph, …)
        $html = (string) ($spec['innerHTML'] ?? ($spec['html'] ?? ''));

        // innerContent MUST have one null placeholder per inner block, else WP serialize_block()
        // iterates zero chunks and silently drops all children.
        $innerContent = [];
        if ($html !== '') $innerContent[] = $html;
        foreach ($inner as $_) $innerContent[] = null;

        return ['blockName' => $bn, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => $innerContent];
    }

    /**
     * Flatten friendly {name:value} into ACF block-data format (with _name=>field_key mirrors),
     * recursively for repeater/group/flexible_content. Only fields present in $values are written.
     */
    static function flatten($schema, $values, $prefix, &$flat) {
        $skip = ['accordion', 'tab', 'message'];
        foreach ($schema as $f) {
            $type = $f['type'] ?? '';
            $name = $f['name'] ?? '';
            if ($name === '' || in_array($type, $skip, true)) continue;
            if (!array_key_exists($name, $values)) continue;
            $val = $values[$name];
            $fk = $prefix === '' ? $name : $prefix . '_' . $name;
            $key = $f['key'];

            if ($type === 'repeater') {
                if ($val !== null && $val !== [] && (!is_array($val) || !array_is_list($val))) {
                    throw new \InvalidArgumentException("Field '$fk' (repeater) expects a LIST of row objects, e.g. [{sub:val}, …] — got a flat/assoc value. Note: block_get shows data FLATTENED (buttons_0_button); block_update/block_replace want the NESTED shape.");
                }
                $rows = is_array($val) ? array_values($val) : [];
                $flat[$fk] = count($rows);
                $flat['_' . $fk] = $key;
                foreach ($rows as $i => $row) {
                    self::flatten($f['sub_fields'] ?? [], is_array($row) ? $row : [], $fk . '_' . $i, $flat);
                }
            } elseif ($type === 'group') {
                $flat['_' . $fk] = $key;
                self::flatten($f['sub_fields'] ?? [], is_array($val) ? $val : [], $fk, $flat);
            } elseif ($type === 'flexible_content') {
                if ($val !== null && $val !== [] && (!is_array($val) || !array_is_list($val))) {
                    throw new \InvalidArgumentException("Field '$fk' (flexible_content) expects a LIST of layout rows, each with an acf_fc_layout key.");
                }
                $rows = is_array($val) ? array_values($val) : [];
                $layoutNames = [];
                foreach ($rows as $i => $row) {
                    $ln = is_array($row) ? ($row['acf_fc_layout'] ?? null) : null;
                    $layoutNames[] = $ln;
                    foreach (($f['layouts'] ?? []) as $L) {
                        if (($L['name'] ?? '') === $ln) {
                            self::flatten($L['sub_fields'] ?? [], $row, $fk . '_' . $i, $flat);
                            break;
                        }
                    }
                }
                $flat[$fk] = $layoutNames;
                $flat['_' . $fk] = $key;
            } else {
                $flat[$fk] = $val;
                $flat['_' . $fk] = $key;
            }
        }
    }

    /** Collect the exact existing flat keys that belong to a field (for precise replace). */
    static function collect_field_keys($f, $data, $prefix, &$keys) {
        $type = $f['type'] ?? '';
        $name = $f['name'] ?? '';
        if ($name === '') return;
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
        } elseif ($type === 'flexible_content') {
            $rows = (isset($data[$fk]) && is_array($data[$fk])) ? $data[$fk] : [];
            foreach ($rows as $i => $ln) {
                foreach (($f['layouts'] ?? []) as $L) {
                    if (($L['name'] ?? '') === $ln) {
                        foreach ($L['sub_fields'] ?? [] as $sf) self::collect_field_keys($sf, $data, $fk . '_' . $i, $keys);
                    }
                }
            }
        }
    }
}
