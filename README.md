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

| Інструмент | Призначення |
|---|---|
| `wp_cli` | Будь-яка команда WP-CLI (без «wp»). `--path` додається сам. Deny-list на руйнівне. |
| `get_post` | Прочитати пост/сторінку (сирий блоковий `post_content`). |
| `update_post` | Оновити безпечно: `wp_slash` + перевірка `content_verified` байт-у-байт. |
| `acf_get` / `acf_update` | ACF через рідний `get_field`/`update_field` (репітери/flex). |
| `upload_media` | Залити файл (base64 або url) через `media_handle_sideload` → **тема ресайзить + робить webp**. |
| `upload_begin` / `upload_chunk` / `upload_finish` | Частинкове завантаження **великих** файлів (відео). |

`wp_cli` — універсальний шлюз. Решта — безпечні обгортки для того, що через CLI роблять
погано: бінарні аплоади, блоковий JSON (щоб не побити `\uXXXX`), складні ACF-поля.

## Безпека

- **HTTPS-only** (крім `SIMPLE_MCP_ALLOW_INSECURE` для локалки).
- Ключ ≥64 символи, у БД лише **SHA-256**, звірка `hash_equals`. Тільки заголовок `Authorization: Bearer`
  (в URL ключ не передаємо — щоб не тік у логи/проксі).
- **Deny-list** для руйнівних команд (`db drop`, `db reset`, `site empty`, `eval`, …).
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

Гілку можна змінити: `define('SIMPLE_MCP_GITHUB_BRANCH', 'main');` у `wp-config.php` (типово `master`).

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
