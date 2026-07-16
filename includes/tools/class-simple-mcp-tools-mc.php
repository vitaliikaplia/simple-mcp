<?php
/**
 * Multi-currency toolset (optional, needs the wp-loc-multicurrency companion plugin).
 *
 * Model: one config option (wp_loc_mc_config) holds currencies + rates + the language→currency
 * map; rates mean "1 base unit = rate target units" (base = WooCommerce's own currency).
 * Per-product overrides are WPML-compatible meta (_regular_price_{CODE}, _sale_price_{CODE},
 * _price_{CODE} + _wcml_custom_prices_status) and are read from the SOURCE (default-language)
 * product — the converter falls back there via wpml_object_id, so overrides on translations
 * are ignored. NB: when the engine is disabled, only WP_LOC_MC_Settings is loaded — write
 * paths therefore avoid the Exchange_Rates/Converter classes.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_MC {

    static function defs() {
        return [
            'mc_get_config' => [
                'description' => 'Read the multi-currency configuration: enabled flag, mode ("language" = currency follows the site language, "switcher" = customer picks via cookie), base currency (WooCommerce\'s own — always rate 1.0), configured currencies with their rates/rounding/decimals, the language→currency map (keys are wp-loc language SLUGS), and the switcher default. Rates mean: 1 unit of base currency = rate units of target. enabled:false means the engine is dormant (prices show in base currency) but config edits still persist.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'callback' => [__CLASS__, 'get_config'],
            ],
            'mc_set_rate' => [
                'description' => 'Set the exchange rate for a configured currency (1 base unit = rate target units). The BASE currency cannot have a rate (always 1.0) — to change the base, change WooCommerce\'s own currency option instead. Also stamps rates_updated_at. Per-product price overrides (mc_set_product_prices) win over rate conversion.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'currency' => ['type' => 'string', 'description' => 'currency code, e.g. USD'],
                        'rate'     => ['type' => 'number', 'description' => '1 base unit = rate target units; must be > 0'],
                    ],
                    'required' => ['currency', 'rate']],
                'callback' => [__CLASS__, 'set_rate'],
            ],
            'mc_set_product_prices' => [
                'description' => 'Set (or clear) per-product price overrides for one currency. Overrides beat rate conversion on the storefront. IMPORTANT: overrides only take effect on the SOURCE (default-language) product — this tool auto-resolves any translation ID to the source and writes there (response shows source_product_id). For VARIABLE products pass the variation ID (prices are per-variation). Pass null for regular_price/sale_price to CLEAR that override (falls back to rate conversion); omit to leave untouched. The effective _price_{CODE} is recomputed (sale wins when set). enabled toggles the per-product "custom prices" flag (_wcml_custom_prices_status); it auto-enables when you set a price while the flag is off.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'product_id'    => ['type' => 'integer', 'description' => 'product or product_variation ID (any language — auto-resolves to the source)'],
                        'currency'      => ['type' => 'string'],
                        'regular_price' => ['type' => ['number', 'null'], 'description' => 'null clears the override'],
                        'sale_price'    => ['type' => ['number', 'null'], 'description' => 'null clears the override'],
                        'enabled'       => ['type' => 'boolean', 'description' => 'the per-product custom-prices flag'],
                    ],
                    'required' => ['product_id', 'currency']],
                'callback' => [__CLASS__, 'set_product_prices'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    static function get_config($args) {
        if (!class_exists('WP_LOC_MC_Settings')) return self::err('wp-loc-multicurrency is not active');
        $cfg = WP_LOC_MC_Settings::get_config();
        return self::ok([
            'enabled'               => WP_LOC_MC_Settings::is_enabled(),
            'mode'                  => WP_LOC_MC_Settings::get_mode(),
            'base_currency'         => WP_LOC_MC_Settings::base_currency(),
            'currencies'            => WP_LOC_MC_Settings::get_currencies(),
            'currency_codes'        => WP_LOC_MC_Settings::get_currency_codes(),
            'language_currency_map' => $cfg['language_currency_map'] ?? [],
            'switcher_default'      => WP_LOC_MC_Settings::get_switcher_default(),
            'rates_updated_at'      => $cfg['rates_updated_at'] ?? null,
        ]);
    }

    static function set_rate($args) {
        if (!class_exists('WP_LOC_MC_Settings')) return self::err('wp-loc-multicurrency is not active');
        $code = strtoupper(trim((string) ($args['currency'] ?? '')));
        $rate = (float) ($args['rate'] ?? 0);
        if ($code === '') return self::err('currency required');
        if (!WP_LOC_MC_Settings::is_valid_currency($code)) {
            return self::err('unknown currency "' . $code . '" — configured: ' . implode(', ', WP_LOC_MC_Settings::get_currency_codes()));
        }
        if ($code === WP_LOC_MC_Settings::base_currency()) {
            return self::err('"' . $code . '" is the BASE currency (always rate 1.0) — change woocommerce_currency to switch the base');
        }
        if ($rate <= 0) return self::err('rate must be > 0');

        if (class_exists('WP_LOC_MC_Exchange_Rates')) {
            WP_LOC_MC_Exchange_Rates::set($code, $rate);
        } else {
            // Двигун вимкнено → клас курсів не завантажений; дзеркалимо його set()
            $config = WP_LOC_MC_Settings::get_config();
            if (!isset($config['currencies'][$code])) $config['currencies'][$code] = [];
            $config['currencies'][$code]['rate'] = $rate;
            $config['rates_updated_at'] = time();
            WP_LOC_MC_Settings::update($config);
        }
        return self::ok([
            'currency'      => $code,
            'rate'          => $rate,
            'base_currency' => WP_LOC_MC_Settings::base_currency(),
            'enabled'       => WP_LOC_MC_Settings::is_enabled(),
        ]);
    }

    static function set_product_prices($args) {
        if (!class_exists('WP_LOC_MC_Settings')) return self::err('wp-loc-multicurrency is not active');
        $id   = intval($args['product_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('product not found');
        if (!in_array($post->post_type, ['product', 'product_variation'], true)) {
            return self::err('post ' . $id . ' is not a product/variation (' . $post->post_type . ')');
        }
        $code = strtoupper(trim((string) ($args['currency'] ?? '')));
        if (!WP_LOC_MC_Settings::is_valid_currency($code)) {
            return self::err('unknown currency "' . $code . '" — configured: ' . implode(', ', WP_LOC_MC_Settings::get_currency_codes()));
        }
        if ($code === WP_LOC_MC_Settings::base_currency()) {
            return self::err('"' . $code . '" is the BASE currency — its prices are the regular WooCommerce prices, no override needed');
        }

        // Оверайди читаються з товару-джерела (дефолтна мова) — пишемо туди ж (як Converter::override_price)
        $default_lang = class_exists('WP_LOC_Languages')
            ? WP_LOC_Languages::get_default_language()
            : apply_filters('wpml_default_language', null);
        $src = (int) apply_filters('wpml_object_id', $id, $post->post_type, true, $default_lang);
        if (!$src) $src = $id;

        $changed = [];
        foreach (['regular_price', 'sale_price'] as $type) {
            if (!array_key_exists($type, $args)) continue;
            $key = '_' . $type . '_' . $code;
            $val = $args[$type];
            if ($val === null || $val === '') {
                delete_post_meta($src, $key);
                $changed[$type] = null;
            } else {
                $dec = wc_format_decimal($val);
                update_post_meta($src, $key, $dec);
                $changed[$type] = $dec;
            }
        }

        // Прапорець custom prices: явний enabled, або авто-ON коли задаємо ціну при вимкненому
        $status = get_post_meta($src, '_wcml_custom_prices_status', true);
        $auto_enabled = false;
        if (array_key_exists('enabled', $args)) {
            $status = !empty($args['enabled']) ? 'yes' : 'no';
            update_post_meta($src, '_wcml_custom_prices_status', $status);
        } elseif ($changed && $status !== 'yes' && array_filter($changed, fn($v) => $v !== null)) {
            $status = 'yes';
            $auto_enabled = true;
            update_post_meta($src, '_wcml_custom_prices_status', $status);
        }

        // Ефективна ціна з РЕЗУЛЬТУЮЧОГО стану мети (sale перемагає) — як в адмін-сейві аддона
        $regular = (string) get_post_meta($src, '_regular_price_' . $code, true);
        $sale    = (string) get_post_meta($src, '_sale_price_' . $code, true);
        $price   = $sale !== '' ? $sale : $regular;
        if ($price === '') delete_post_meta($src, '_price_' . $code);
        else update_post_meta($src, '_price_' . $code, $price);

        // Кеш цін WC (+ батько для варіації)
        wc_delete_product_transients($src);
        if (class_exists('WC_Cache_Helper')) WC_Cache_Helper::invalidate_cache_group('product_' . $src);
        $parent = wp_get_post_parent_id($src);
        if ($parent) wc_delete_product_transients($parent);

        return self::ok([
            'product_id'         => $id,
            'source_product_id'  => $src,
            'resolved_to_source' => $src !== $id,
            'currency'           => $code,
            'custom_prices'      => $status === 'yes',
            'auto_enabled'       => $auto_enabled,
            'regular_price'      => $regular !== '' ? $regular : null,
            'sale_price'         => $sale !== '' ? $sale : null,
            'price'              => $price !== '' ? $price : null,
            'engine_enabled'     => WP_LOC_MC_Settings::is_enabled(),
        ]);
    }
}
