<?php
/**
 * WooCommerce-sync toolset (optional, needs the wp-loc-woocommerce companion plugin).
 *
 * Model: the DEFAULT-LANGUAGE product is the source of truth for all non-text product
 * data (prices, stock, SKU, attributes, variations — see wc_synced_meta_keys). The addon
 * mirrors that data to translations on save; these tools expose the same sync on demand,
 * so an AI that just edited product data via MCP can push it to translations immediately
 * instead of waiting for the next admin save.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_WC {

    static function defs() {
        return [
            'wc_sync_product' => [
                'description' => 'Sync WooCommerce product data across languages (wp-loc). The default-language product is the source of truth for prices, stock, SKU, attributes and variations; this pushes that data from the product to all its translations (or pulls FROM the source when called on a translation). Call it after editing any synced product data via MCP. WARNING: MUTATES translations — their synced meta is overwritten with source values and orphan mirror variations are deleted; translated TEXT (title, description) is never touched. Accepts a variation ID (resolves to the parent product).',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['product_id' => ['type' => 'integer']],
                    'required'   => ['product_id']],
                'callback' => [__CLASS__, 'sync_product'],
            ],
            'wc_synced_meta_keys' => [
                'description' => 'List the product meta keys that wp-loc-woocommerce mirrors from the default-language product to its translations. NEVER edit these keys per-language on a translation — the next sync overwrites them; edit the SOURCE product and run wc_sync_product instead. Anything not listed (title, description, ACF text fields) is per-language and safe to translate.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'callback' => [__CLASS__, 'synced_meta_keys'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    static function sync_product($args) {
        if (!class_exists('WP_LOC_WC')) return self::err('wp-loc-woocommerce is not active');
        $id   = intval($args['product_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('product not found');

        $resolved_from_variation = null;
        if ($post->post_type === 'product_variation') {
            $resolved_from_variation = $id;
            $id   = wp_get_post_parent_id($id);
            $post = $id ? get_post($id) : null;
            if (!$post) return self::err('variation has no parent product');
        }
        if ($post->post_type !== 'product') {
            return self::err('post ' . $id . ' is not a product (' . $post->post_type . ')');
        }

        // Мапу перекладів і напрямок визначаємо ДО синку (sync_product повертає void)
        $etype = 'post_product';
        $lang  = apply_filters('wpml_element_language_code', null, ['element_id' => $id, 'element_type' => $etype]);
        $def   = apply_filters('wpml_default_language', null);
        $trid  = apply_filters('wpml_element_trid', null, $id, $etype);
        if (!$lang || !$trid) {
            return self::ok(['product_id' => $id, 'synced' => false,
                'note' => 'product has no wp-loc language row — nothing to sync']);
        }

        $translations = [];
        $source_id    = $id;
        foreach ((array) apply_filters('wpml_get_element_translations', null, $trid, $etype) as $code => $obj) {
            $tid = is_object($obj) ? ($obj->element_id ?? null) : ($obj['element_id'] ?? null);
            if ($code === $def) $source_id = intval($tid);
            if (intval($tid) !== $id) $translations[$code] = intval($tid);
        }
        $is_source = ($lang === $def);

        WP_LOC_WC::instance()->variations->sync_product($id);

        return self::ok([
            'product_id'          => $id,
            'resolved_from_variation' => $resolved_from_variation,
            'language'            => $lang,
            'direction'           => $is_source ? 'push' : 'pull',
            'source_id'           => $source_id,
            // push: джерело → всі переклади; pull: джерело → лише цей переклад
            'synced_translations' => $is_source ? $translations : [$lang => $id],
            'trid'                => intval($trid),
            'synced'              => true,
        ]);
    }

    static function synced_meta_keys($args) {
        if (!class_exists('WP_LOC_WC_Variations')) return self::err('wp-loc-woocommerce is not active');
        return self::ok([
            'synced_meta_keys' => WP_LOC_WC_Variations::synced_meta_keys(),
            'note' => 'These keys mirror FROM the default-language product on every sync — edit them on the source and run wc_sync_product; never per-language.',
        ]);
    }
}
