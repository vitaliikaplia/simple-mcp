# Simple MCP

Приватний MCP-сервер для WordPress. Дає AI-асистенту (Claude Code) працювати з сайтом
«наче він локальний»: повний доступ до **WP-CLI** + безпечні інструменти для **медіа**,
**Gutenberg-блоків** та **ACF** — через власний ендпоінт **поза** WP REST API,
захищений одним довгим ключем.

> Не для публікації. Для власних проєктів.

## Чому не готовий плагін

Проаналізовано `ai-engine`, `easy-mcp-ai`, `royal-mcp`. Усі:
- сидять на **WP REST API** (а якщо REST вимкнено для анонімів — їхній ендпоінт не працює);
- **не мають** WP-CLI passthrough;
- несуть баласт (OAuth-сервери, 5–7 таблиць, SEO/GA4/WooCommerce, телеметрію, upsell).

Simple MCP бере з них найкраще (`media_handle_sideload`, `wp_slash`+round-trip verify,
ACF через `update_field`, hashed-токен, rate-limit, audit-log) і викидає решту.

## Встановлення

1. Плагін лежить у `wp-content/plugins/simple-mcp`. Активуй у **Плагіни**.
2. **Налаштування → Simple MCP** → «Згенерувати новий ключ». Скопіюй ключ (показується раз).
3. Перевір, що `wp` (WP-CLI) доступний веб-серверу і `proc_open` не вимкнено.

## Підключення в Claude Code

```bash
claude mcp add --transport http simple-mcp https://САЙТ/simple-mcp \
  --header "Authorization: Bearer ВАШ_КЛЮЧ"
```

Для локалки без HTTPS додай у `wp-config.php`: `define('SIMPLE_MCP_ALLOW_INSECURE', true);`

## Транспорт

- MCP **Streamable HTTP** + **JSON-RPC 2.0**, protocol `2025-06-18`.
- Ендпоінт ловиться на `do_parse_request` — **не** через `register_rest_route`,
  тому вимкнений REST API на анонімів на нього не впливає.
- Методи: `initialize`, `tools/list`, `tools/call`, `ping`.

## Інструменти

> 📖 **Повний гайд для агентів:** [docs/MCP-GUIDE.md](docs/MCP-GUIDE.md) — модель контенту,
> рецепти й пастки (як не зламати блоки, мультимовність, опції). Ключові правила також
> прокидуються агенту через MCP `instructions` при підключенні.

**Ядро**
| Інструмент | Призначення |
|---|---|
| `wp_cli` | Будь-яка команда WP-CLI (без «wp»). Deny-list + блок метасимволів. Тільки контент, **не код**. |
| `get_post` / `update_post` | Читання / block-safe запис (`wp_slash` + auto-revision + `content_verified`). |
| `acf_get` / `acf_update` | ACF на **пості/user/term/опціях** (не блокові поля). |
| `upload_media` · `upload_begin`/`chunk`/`finish` | Медіа через `media_handle_sideload` → **ресайз + webp** (chunked для великих). |

**Блоки** (ACF-поля всередині Гутенберг-блоків — inline в `post_content`)
| Інструмент | Призначення |
|---|---|
| `block_get` / `list_block_fields` | Читання блоків сторінки / схема полів блоку (з ACF-реєстру). |
| `block_update` | Безпечна правка ACF-поля(ів) блоку (server-side flattener + field_key mirror + verify). |
| `block_insert` / `block_move` / `block_remove` / `block_replace` | Структура сторінки з JSON-специфікацій. |

**Мультимовність** (детект wp-loc/WPML)
| `wploc_get_translations` / `wploc_link_translation` / `wploc_create_translation` | Резолв/лінк/створення перекладів (trid, slug↔wpml_code). |

**Контент і дискавері**
| `create_post` | Створити пост/сторінку/CPT з block-safe тілом. |
| `render_post` | Рендер `do_blocks` HTML для верифікації. |
| `safe_delete` | Translation-aware видалення (не каскадить переклади). |
| `describe_site` | Схема форку: блоки+поля, ACF-опції, CPT/таксономії, мовна мапа. |

**WooCommerce-синк** (детект wp-loc-woocommerce)
| `wc_sync_product` | Синк цін/стоку/SKU/варіацій з товару-джерела на переклади (push/pull). |
| `wc_synced_meta_keys` | Мета-ключі, що дзеркаляться між мовами — їх не редагувати per-language. |

**Мультивалютність** (детект wp-loc-multicurrency)
| `mc_get_config` | Валюти, курси, режим (language/switcher), мапа мова→валюта. |
| `mc_set_rate` | Курс валюти (1 базова = rate цільових); базову не чіпає. |
| `mc_set_product_prices` | Пер-валютні ціни товару/варіації — авто-резолв на товар-джерело. |

### Модулі (вмик/вимк в адмінці)
**Налаштування → Simple MCP → Модулі інструментів.** Групи можна вимикати — тоді їхні тули
повністю приховані від агента (не в `tools/list`, не викликаються), а `instructions`
підлаштовуються:
- **Ядро контенту** — завжди ON.
- **`wp_cli`** — вимкни для «typed-only» режиму (максимально безпечно).
- **Блоки**, **Контент і дискавері** — за потреби.
- **Мультимовність** — з **авто-детектом**: показує «Виявлено: WP-LOC / WPML», а якщо жодної
  системи нема — група прихована автоматично.
- **WooCommerce-синк** — авто-детект `wp-loc-woocommerce`; без нього група прихована.
- **Мультивалютність** — авто-детект `wp-loc-multicurrency`; без нього група прихована.

## Безпека

- **HTTPS-only** (крім `SIMPLE_MCP_ALLOW_INSECURE` для локалки).
- Ключ ≥64 символи, у БД лише **SHA-256**, звірка `hash_equals`. Тільки заголовок `Authorization: Bearer`
  (в URL ключ не передаємо — щоб не тік у логи/проксі).
- **Deny-list** для руйнівних команд (`db drop`, `db reset`, `site empty`, `eval`, …).
- **Серверні операції** (`wp-config` directives + `plugin/theme install/update/delete`) —
  за замовчуванням **заблоковані**; вмикаються тумблером «Серверні операції» per-site
  (конфіг і набір плагінів законно різняться між середовищами — у git лише тема). Читання
  конфігу (`config get/list`) дозволене завжди. Руйнівне агент має **перепитувати**.
- **Rate-limit** по IP, опційний **IP-allowlist** (IP/CIDR).
- **Audit-log** кожного виклику (таблиця `{prefix}simple_mcp_log`, перегляд в адмінці).
- **Kill-switch**: `define('SIMPLE_MCP_DISABLE', true);` у `wp-config.php`.
- SSRF-захист на завантаження з URL (блок приватних діапазонів).

> `wp_cli` — це керований **RCE за задумом**. Ключ = адмін-пароль. Рекомендація:
> обкатати на **локалці → staging**, на проді — IP-allowlist/VPN.

**Про deny-list.** Команди виконуються токенізовано, **без шелла** (`proc_open` argv),
тож чейнінг (`;`, `&&`, `` ` ``, `$()`), лапкові трюки (`db "drop"`) і провідні глобальні
прапорці (`--quiet db drop`) не обходять фільтр; `--exec/--require/--ssh/--http` заблоковані
окремо. Але з увімкненим `wp_cli` deny-list — це **запобіжник від випадковостей, а не пісочниця**:
той, хто має ключ, все одно може досягти руйнівного ефекту іншою командою (напр. `db query`).
Це прийнята модель (ключ = повний доступ). Хочеш жорсткого обмеження — вимкни `wp_cli` і лишись
на типізованих інструментах, або звузь права тех-користувача.

**Про `upload_media` з `url`.** Є SSRF-гейт (схема http/https + перевірка A/AAAA на приватні
діапазони) плюс core `wp_safe_remote_get`. Залишковий ризик — DNS-rebinding, тому для
**недовірених** джерел завантажуй через `base64`, а не `url`.

## Авто-оновлення через GitHub

Плагін оновлюється стандартним механізмом WordPress із публічного репозиторію
[`vitaliikaplia/simple-mcp`](https://github.com/vitaliikaplia/simple-mcp):

- Раз на 12 год (та вручну на сторінці **Плагіни → Перевірити оновлення** з `?force-check=1`)
  читається `Version:` із `raw.githubusercontent.com/.../simple-mcp.php`.
- Якщо версія на GitHub новіша за встановлену — з'являється звичайне «Доступне оновлення».
- Пакет — zip гілки (`archive/refs/heads/<branch>.zip`); тека нормалізується під slug `simple-mcp`.
- Щоб випустити оновлення: підніми `Version:` у заголовку `simple-mcp.php` і запуш у гілку.

Типова гілка — **`master`** (як у `wp-loc`). За потреби перевизначається константою
`define('SIMPLE_MCP_GITHUB_BRANCH', '<гілка>');` у `wp-config.php`.

## Константи (`wp-config.php`)

| Константа | Дія |
|---|---|
| `SIMPLE_MCP_DISABLE` | `true` — миттєво вимкнути сервер. |
| `SIMPLE_MCP_ALLOW_INSECURE` | `true` — дозволити HTTP (лише для локалки). |
| `SIMPLE_MCP_WP_BIN` | Шлях до бінарника `wp`, якщо не в PATH. |
| `SIMPLE_MCP_PHP_BIN` | Шлях до CLI-`php` для wp-cli. |
| `SIMPLE_MCP_GITHUB_BRANCH` | Гілка для авто-оновлення (типово `master`). |

## Вимоги

PHP 8.0+, WP-CLI на сервері, доступний `proc_open`/`exec`. ACF-інструменти — за наявності ACF.
