<?php
/**
 * Інструменти інших плагінів і тем — через WordPress Abilities API (ядро 6.9+), без власного механізму реєстрації.
 *
 * Плагін чи тема реєструє ability як завжди (wp_register_ability на дії wp_abilities_api_init, категорія — на
 * wp_abilities_api_categories_init) і позначає її для MCP за конвенцією офіційного WordPress MCP Adapter:
 *   'meta' => ['mcp' => ['public' => true]]   — лише для MCP, або
 *   'meta' => ['public' => true]              — для всіх клієнтів (meta.mcp.public = false вимикає MCP).
 * meta.mcp.type — лише 'tool' (типово); ресурси й промпти адаптера Simple MCP не показує.
 *
 * Simple MCP показує такі abilities агенту як інструменти й викликає їх через WP_Ability: схему входу перевіряє
 * ядро (rest_validate_value_from_schema), права — permission_callback самої ability (запит уже виконується від
 * імені власника ключа чи OAuth-підключення), а Simple MCP додає свої шари: групу «Інструменти плагінів і теми»
 * в матриці ролей, аудит, rate-limit, HTTPS/OAuth. Порядок завантаження плагінів не важливий
 * (wp_abilities_api_init спрацьовує ліниво), жорсткої залежності немає: без Simple MCP ability просто лежить у ядрі.
 *
 * Зіставлення ability → інструмент MCP (як у McpNameSanitizer адаптера):
 *   ім'я       my-plugin/do-thing → my-plugin-do-thing (інструменти Simple MCP мають «_», тож колізій немає);
 *   title      label;  description — опис + «(WordPress ability my-plugin/do-thing)», до 2048 символів;
 *   inputSchema — схема об'єкта як є (булеві required властивостей піднімаються в масив required батька,
 *              WP-ключі context/arg_options/readonly прибираються); не-об'єктна схема загортається в {input: …},
 *              без схеми — порожній об'єкт;
 *   annotations — readonly/destructive/idempotent (або readOnlyHint/…, як приймає адаптер) → MCP-підказки.
 * Коли wp-loc реєструє власні abilities (wp-loc/…), вбудовані wploc_* Simple MCP ховає (Simple_MCP_Tools::registry).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Abilities {

    const DESC_MAX = 2048;
    /** WP-ключі схем REST, яких не знає JSON Schema */
    const WP_ONLY_KEYS = ['context', 'arg_options', 'readonly', 'sanitize_callback', 'validate_callback'];

    /** Чи є Abilities API (WordPress 6.9+) */
    static function available() {
        return function_exists('wp_get_abilities') && class_exists('WP_Ability');
    }

    // ── Чисте зіставлення (тестується без WordPress) ──────────────────────

    /** Чи позначено ability для MCP (конвенція офіційного MCP Adapter) */
    static function exposed_meta(array $meta) {
        $mcp  = isset($meta['mcp']) && is_array($meta['mcp']) ? $meta['mcp'] : [];
        $type = array_key_exists('type', $mcp) ? $mcp['type'] : 'tool';
        if ($type !== 'tool') return false;
        if (array_key_exists('public', $mcp)) return $mcp['public'] === true;
        return ($meta['public'] ?? null) === true;
    }

    /** Ім'я інструмента MCP з імені ability: «/» та інше недозволене → «-», без подвійних і крайніх дефісів, до 128 */
    static function tool_name($ability_name) {
        $n = strtolower(trim((string) $ability_name));
        $n = (string) preg_replace('/[^a-z0-9_-]+/', '-', $n);
        $n = (string) preg_replace('/-{2,}/', '-', $n);
        return substr(trim($n, '-_'), 0, 128);
    }

    /**
     * Схема REST → JSON Schema для MCP-клієнтів: булевий required властивості (стиль WP REST) піднімається в
     * масив required її об'єкта, WP-ключі (context, arg_options, …) прибираються. Рекурсивно.
     */
    static function normalize_schema($schema) {
        if (!is_array($schema)) return $schema;
        if (array_is_list($schema)) return array_map([__CLASS__, 'normalize_schema'], $schema);
        $out = [];
        foreach ($schema as $k => $v) {
            if (in_array($k, self::WP_ONLY_KEYS, true)) continue;
            if ($k === 'required' && is_bool($v)) continue; // піднімається батьком нижче
            if ($k === 'properties' && is_array($v)) {
                $props = [];
                $req   = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
                foreach ($v as $name => $sub) {
                    if (is_array($sub) && ($sub['required'] ?? null) === true && !in_array($name, $req, true)) $req[] = (string) $name;
                    $props[$name] = self::normalize_schema($sub);
                }
                $out['properties'] = $props;
                if ($req) $out['required'] = array_values($req);
                continue;
            }
            if ($k === 'required' && isset($out['required'])) continue; // уже злито з піднятими
            $out[$k] = self::normalize_schema($v);
        }
        return $out;
    }

    /**
     * inputSchema інструмента й чи загорнуто вхід: [схема, wrapped]. Інструмент MCP приймає лише об'єкт, тож
     * не-об'єктну схему ability (рядок, масив, число) загортаємо в {input: …}.
     */
    static function input_shape(array $schema) {
        if (!$schema) return [['type' => 'object', 'properties' => []], false];
        $type = $schema['type'] ?? null;
        if ($type === 'object' || ($type === null && isset($schema['properties']))) {
            return [['type' => 'object'] + $schema, false];
        }
        $wrapped = ['type' => 'object', 'properties' => ['input' => $schema]];
        if (!array_key_exists('default', $schema)) $wrapped['required'] = ['input'];
        return [$wrapped, true];
    }

    /** meta.annotations ability (WP- або MCP-назви) → MCP-підказки; невідоме — обережні типові */
    static function annotations(array $meta) {
        $a    = isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];
        $pick = function ($wp, $mcp, $default) use ($a) {
            if (isset($a[$mcp]) && is_bool($a[$mcp])) return $a[$mcp];
            if (isset($a[$wp]) && is_bool($a[$wp])) return $a[$wp];
            return $default;
        };
        $ro = $pick('readonly', 'readOnlyHint', false);
        return [
            'readOnlyHint'    => $ro,
            'destructiveHint' => $pick('destructive', 'destructiveHint', !$ro),
            'idempotentHint'  => $pick('idempotent', 'idempotentHint', $ro),
            'openWorldHint'   => $pick('open_world', 'openWorldHint', false),
        ];
    }

    /** Чи серед імен abilities є хоч одна з простору імен $ns («wp-loc» для «wp-loc/…») */
    static function provides_namespace(array $ability_names, $ns) {
        foreach ($ability_names as $n) {
            if (strpos((string) $n, $ns . '/') === 0) return true;
        }
        return false;
    }

    // ── Реєстр abilities ──────────────────────────────────────────────────

    /**
     * Abilities, позначені для MCP: ['tools' => [ім'я інструмента => WP_Ability], 'hidden' => [ім'я ability => причина]].
     * Фільтр simple_mcp_expose_ability (bool, WP_Ability) змінює рішення для окремої ability.
     */
    static function scan() {
        static $cache = null;
        if ($cache !== null) return $cache;
        $tools  = [];
        $hidden = [];
        if (!self::available()) return ['tools' => [], 'hidden' => []];
        foreach ((array) wp_get_abilities() as $ability) {
            if (!$ability instanceof WP_Ability) continue;
            $name = $ability->get_name();
            $meta = (array) $ability->get_meta();
            if (!apply_filters('simple_mcp_expose_ability', self::exposed_meta($meta), $ability)) {
                $hidden[$name] = 'немає позначки MCP (meta.mcp.public або meta.public)';
                continue;
            }
            $tool = self::tool_name($name);
            if ($tool === '') {
                $hidden[$name] = 'ім\'я не перетворюється на ім\'я інструмента';
            } elseif (isset($tools[$tool])) {
                $hidden[$name] = 'те саме ім\'я інструмента, що й у ' . $tools[$tool]->get_name();
            } else {
                $tools[$tool] = $ability;
            }
        }
        // кешуємо лише після wp_abilities_api_init (до init реєстр ще порожній)
        if (did_action('init')) $cache = ['tools' => $tools, 'hidden' => $hidden];
        return ['tools' => $tools, 'hidden' => $hidden];
    }

    /** Чи плагін із простором імен $ns сам показує агенту свої abilities (тоді вбудовані дублікати ховаються) */
    static function provides($ns) {
        $names = [];
        foreach (self::scan()['tools'] as $ability) $names[] = $ability->get_name();
        return self::provides_namespace($names, $ns);
    }

    static function defs() {
        $defs = [];
        foreach (self::scan()['tools'] as $tool => $ability) {
            $meta = (array) $ability->get_meta();
            [$schema, $wrapped] = self::input_shape((array) self::normalize_schema((array) $ability->get_input_schema()));
            $label = trim((string) $ability->get_label());
            $desc  = trim((string) $ability->get_description());
            $desc  = ($desc !== '' ? $desc : $label) . ' (WordPress ability ' . $ability->get_name() . ')';
            if (function_exists('mb_strlen') && mb_strlen($desc) > self::DESC_MAX) {
                $desc = mb_substr($desc, 0, self::DESC_MAX - 1) . '…';
            }
            $defs[$tool] = [
                'title'        => $label !== '' ? $label : $tool,
                'description'  => $desc,
                'inputSchema'  => $schema,
                'annotations'  => self::annotations($meta),
                'callback'     => function ($args) use ($ability, $wrapped) { return self::run($ability, $args, $wrapped); },
                // вхід перевіряє сама ability (схема WP REST), не наш валідатор-підмножина
                'validated_by' => 'ability',
            ];
        }
        return $defs;
    }

    /** Виклик ability: вхід → перевірка схемою → права → execute; WP_Error стає isError */
    static function run(WP_Ability $ability, $args, $wrapped) {
        $args   = is_array($args) ? $args : [];
        $schema = (array) $ability->get_input_schema();
        if ($wrapped) {
            $input = array_key_exists('input', $args) ? $args['input'] : null;
        } elseif (!$schema) {
            $input = null;
        } else {
            // порожній виклик ability зі схемою, що має default, бере default (WP_Ability::normalize_input)
            $input = ($args === [] && array_key_exists('default', $schema)) ? null : $args;
        }
        $input = $ability->normalize_input($input);
        $valid = $ability->validate_input($input);
        if (is_wp_error($valid)) return Simple_MCP_Tools::err($valid->get_error_message());
        // Права перевіряємо самі: execute() на WP_Error з permission_callback ще й кидає _doing_it_wrong (нотис)
        if ($ability->check_permissions($input) !== true) {
            return Simple_MCP_Tools::err_cap($ability->get_name());
        }
        $result = $ability->execute($input);
        if (is_wp_error($result)) return Simple_MCP_Tools::err($result->get_error_message());
        return Simple_MCP_Tools::ok($result);
    }
}
