# Tests

No OpenCart installation and no database are needed. The tests need the sources of OpenCart 3.0.5.1 (`Url`, `Request`, `utf8`, Twig, the admin controllers, models, templates and language files) and `curl`.

```sh
OC_DIR=/path/to/opencart-3.0.5.1/upload sh tests/run.sh
```

`DEALS_DIR=/path/to/OCTemplates-Deals/upload` adds the check of `install.xml` together with the theme Deals (skipped without it). `PHP=/path/to/php` chooses the PHP binary, `PORT=8765` the port of the built-in server. The script prints `PASS` or `FAIL` for each part and the failed checks. The exit code is 0 only when every part passed. The server is stopped when the script ends.

## Parts

| Part | Command | Fails with |
|---|---|---|
| OCMOD: `install.xml` applied to the core the way Refresh does | `php tests/ocmod_sim.php --check=install.xml <core with upload/> <out dir> $OC_DIR/system/modification.xml seo_pro_oc3.ocmod/install.xml` | exit code 1 |
| Controller: links, decoding, redirects, cached paths in groups (a miss writes only its group, a group is read once per request) | `curl -f http://127.0.0.1:8765/cases.php` under `OC_DIR=... php -S 127.0.0.1:8765 -t tests` | HTTP 500 |
| OCMOD: the synthetic extension `tests/foreign.ocmod.xml` (its own kinds of `seo_url.query` in `index()` and `rewrite()` of the router of the core) applied with `install.xml` | `php tests/ocmod_sim.php <core with upload/> <foreign dir> $OC_DIR/system/modification.xml tests/foreign.ocmod.xml seo_pro_oc3.ocmod/install.xml` | exit code 1 |
| Controller, records of other extensions, the router of the core changed by the synthetic extension: links, addresses, parameters of the visitor, redirects, queries, records changed with a warm cache; a store without foreign records | `curl -f 'http://127.0.0.1:8765/cases_foreign.php?core=patched'` under the same server started with `SEO_PRO_FOREIGN_DIR=<foreign dir>` | HTTP 500 |
| The same records with the unmodified router of the core: 404, plain links | `curl -f 'http://127.0.0.1:8765/cases_foreign.php?core=stock'` | HTTP 500 |
| Schema installer; the rule of an id: `Seo_pro_schema::isId()` without leading zeros, `categoryId()` with them | `php tests/test_schema.php` | exit code 1 |
| Cache resets: every group of paths of both kinds, the keys of 1.7, the maps | `php tests/test_cache.php <out dir>` | exit code 1 |
| Main category of a product: add, edit, copy | `php tests/test_product_categories.php <out dir>` | exit code 1 |
| `audit_seo_url.php --tsv`: foreign and unsupported records, wrong input is never a clean result | `php tests/test_audit.php` | exit code 1 |
| SEO settings of the default store and of additional stores | `OC_DIR=... php tests/test_store_settings.php <out dir>` | exit code 1 |
| HTML H1 (`meta_h1`) of products, categories, information pages: add, edit, copy, partial input, limits, wrong data, raw text of an import, schema not ready | `OC_DIR=... php tests/test_h1.php <out dir>` | exit code 1 |
| Descriptions of manufacturers: add, edit, partial input, new language, delete, wrong data, raw text and HTML of an import, schema not ready, form values | `OC_DIR=... php tests/test_manufacturer_description.php <out dir>` | exit code 1 |
| Storefront: H1, title and meta of product, category, information, manufacturer pages, fallback to the name, raw values escaped | `php tests/test_storefront.php <out dir>` | exit code 1 |
| Admin forms of products, categories, information pages, manufacturers rendered by Twig: fields, ids, values after an error, raw stored values never become markup | `OC_DIR=... php tests/test_content_forms.php <out dir>` | exit code 1 |
| `install.xml` with other modifications: an extension with H1 in both INSERTs, in the add or the edit INSERT only (followed by `test_h1.php` on the result), with manufacturer descriptions, a removed anchor, Deals | `OC_DIR=... [DEALS_DIR=...] php tests/test_ocmod.php` | exit code 1 |

- `<out dir>` holds the core files changed by `ocmod_sim.php`. Several tests run these files, so they test the code the shop will run.
- The shop loads the libraries of `upload/system/library/` (`seo_pro_schema.php`, `seo_pro_cache.php`, `seo_pro_settings.php`) with its autoloader. The tests and `audit_seo_url.php` have no autoloader and `require` the files they need.
- `ocmod_sim.php` ends with `SUMMARY <json>`. An operation whose search is not found and a missing file are failures. Language packs other than `en-gb` are optional: the core without `uk-ua` passes, the files are listed in `missing_optional_files`. Without `--check` every XML on the command line decides the exit code.
- `cases.php` and `cases_foreign.php` run under the built-in server because the module does not redirect under the CLI SAPI. `cases_foreign.php` runs the router of the core itself: the file `ocmod_sim.php` has written for `core=patched`, the file of `OC_DIR` for `core=stock`. A class is loaded once per request, so these are two requests. The answer is HTTP 500 when a check fails, PHP reports a warning or the run does not finish. Always call it with `curl -f`.
- `run.sh` passes the controller part only when the PHP server it has started is alive, answers with the id of this run and the answer ends with the line `seo_pro cases finished <id>`. When the port is in use the part fails, also when the other server answers HTTP 200. By hand, check the last line of the answer yourself: HTTP 200 alone does not prove that `cases.php` was executed.
- `audit_seo_url.php <config.php of the shop>` is not a test. It reads `seo_url` of a shop and lists the records the module does not open itself: `foreign` (a record of another extension, passed to the router of the core since 1.8) and `unsupported` (a wrong record of the module, 404). It writes nothing. Exit code 0: the module opens every record itself; 1: foreign or unsupported records exist; 2: the check could not run or the input could not be read in full. With `--tsv <file>` a BOM, CRLF, empty lines and a header line are accepted, any other line that is not five tab-separated fields with numeric ids stops the check.

## What the tests do not cover

- A real database: the first run of the schema installer in two requests at once, a database user without the ALTER or CREATE privilege, the composite primary key of `product_to_category`, the `store_id` of the saved settings, `ALTER TABLE ... ADD meta_h1` on large tables, `CREATE TABLE manufacturer_description` on MyISAM and InnoDB, truncation by `sql_mode`. The DDL and the checks of existing tables run against a stub.
- The admin forms in a browser: the tabs, Summernote and the JavaScript of the forms. The settings forms and the forms of products, categories, information pages and manufacturers are rendered by Twig from the changed templates and checked as HTML.
- Redis, Mem and Memcached: the cache is a stub that deletes by exact key. The prefix delete of the file cache is not run by the tests.
- Two requests that write the same group of paths at once: the later write still drops the paths the other one added to that group.
- A shop in a subdirectory, a port other than 80 and 443, the templates of themes other than `default`. Other modifications only at the level of OCMOD and `php -l`: two synthetic extensions and, with `DEALS_DIR`, Deals.
- A real extension with its own SEO URLs (a blog, a theme): the router of the core is run with the synthetic modification `foreign.ocmod.xml` only. An extension that redirects or returns an action from `index()` of the router, that reads the parameters of the visitor there, or that builds an address it does not read back is not covered. The stub of the database compares keywords byte by byte, MySQL does not.
- Speed and memory.
- PHP versions other than the ones the script was run on.

## Product navigation

`tests/run.sh` включает Url-проверки fallback/владельцев/SQL и отдельный HTTP-тест с собственным сервером и случайным портом. Последний исполняет неизменённые stock product controller и model; SQL и вспомогательные сервисы заменены fixtures, view выводит данные canonical и крошек. Это не полноценная установка магазина или проверка Deals в браузере. Отсутствующий необязательный ru-ru language pack отмечается `not run` в test_ocmod.php, обязательный en-gb проверяется всегда.

```sh
OC_DIR=/private/tmp/seo-audit-core/opencart-3.0.5.1/upload PHP=/opt/homebrew/bin/php sh tests/run.sh
OC_DIR=/private/tmp/seo-audit-core/opencart-3.0.5.1/upload php tests/test_product_navigation.php
OC_DIR=/private/tmp/seo-audit-core/opencart-3.0.5.1/upload php tests/test_product_navigation_http.php
```

Адресные SQL считаются по фактическим DB-вызовам: `product_address`, `category_address`; metadata отдельно, не более одного запроса. Входящая карта (`max/map/verify/fallback`), `incoming_path` и SQL стороннего ядра учитываются отдельно. Бюджет генерации N+K: 20 товаров в 3 категориях ≤23, в одной ≤21, без основной ≤20; повтор и отрицательный повтор — 0. Это не бюджет всей страницы и не измерение скорости.

### Stand

Источник инструкций и исторических результатов — внешний `SEO_PRO_STAND_TESTS_DIR`, каталог `docs/designs/product-navigation-brainstorm/stand_tests` исходной рабочей копии. Его scripts и baseline_cdecbdc не редактируются и не копируются в ветку. Новые выводы сохраняйте в `runs/<label>/`. Результаты беты не заменяют проверки кандидата.

MySQL harness по умолчанию работает в `--fixtures`; также поддерживает `--read-only-benchmark` и `--stand-scenarios`. Для передачи runtime кандидата через stdin:

```sh
OC_DIR=/private/tmp/seo-audit-core/opencart-3.0.5.1/upload php tests/test_product_navigation_mysql.php --bundle > /tmp/seo-pro-navigation-stdin.php
ssh -o BatchMode=yes mozap-test@141.136.44.128 'SEO_PRO_TEST_STORE_CONFIG=/home/mozap-test/htdocs/test.mozap.ru/config.php SEO_PRO_MYSQL_MODE=--fixtures php' < /tmp/seo-pro-navigation-stdin.php
```

Реквизиты читаются токенами литеральных define без include/eval config.php. Вычисляемое определение и отсутствие подключения — `not run`, exit 2. Секреты не передаются аргументами и не печатаются. Не запускайте исторические изменяющие скрипты напрямую.

Fixtures используют MyISAM, индексы query/keyword по 250 символов и уникальный `seo_pro_fx_<run>_`; runtime получает этот DB_PREFIX до выполнения SQL. В finally удаляется только собственный список таблиц, сверяются их отсутствие и COUNT/MAX настоящей seo_url. CI, CS и binary collation проверяются отдельно; недоступная collation — `not run` с именем сервера. Сравнение с независимым проходом охватывает выбор alias и множества ранних владельцев, а также реальный cafe/café fallback входящего lookup.

Read-only benchmark допускает только SELECT/SHOW/EXPLAIN и вызывает настоящий Url с runtime кандидата. Он не запускает фронт-контроллер, установку схемы, просмотры или запись настроек. EXPLAIN rows — оценки; число строк результата JOIN и ms SQL показаны отдельно. Полный скан seo_url на CI-ветке — ошибка и возврат на согласование.

HTTP `--stand-baseline` / `--stand-candidate` требуют `SEO_PRO_STAND_TESTS_DIR` и `SEO_PRO_STAND_VERIFIED_BUILD=baseline|candidate`, установленный после независимой проверки сборки и файлового кэша. Исходные suite и addr/canon3/mixed3 служат матрицей. Кандидат намеренно меняет ожидания товара 28 и нагрузочных N%7==0; временные сценарии требуют безопасного сценарного режима. На бете baseline 1.8 не снимается. Владелец устанавливает обе сборки через Installer и обновляет модификации. Автоматическая упаковка/установка, смена кэша, gen.php и sitemap18.sh в эти команды не входят.

Без подключения стенда run.sh печатает `not run`, не PASS. Каждый отчёт должен отдельно показывать непроверенные части, совместимость с реальной темой, режим/collation, доказательство эффективного cache_engine, состояние БД до/после и пути дампов изменяющих сценариев.

Сохранение подробных локальных логов: задайте `SEO_PRO_TEST_RESULTS_DIR=<runs/label/local-parts>`. run.sh сохраняет вывод каждой части и полные controller cases, чтобы отчёт мог пересчитать фактические assertions.

Для свежей выборки 300 товаров / 930 HTTP-проверок используйте stdin-bundle в `--stand-scenarios` с `SEO_PRO_STAND_BUILD=baseline|candidate` и `SEO_PRO_STAND_SCENARIO=verify`; этот подрежим не меняет строки каталога. Без селектора выполняются временные keyword/route/foreign/Deals/canon3 сценарии; candidate дополнительно проверяет main/category keyword/hierarchy. Перед каждым изменяющим сценарием создаётся дамп, в finally восстанавливаются сохранённые значения и исходные ID, сверяются строки, COUNT и MAX seo_url. Никогда не выдавайте baseline-проверку за проверку кандидата.

Для canon3 stdin-bundle создавайте с `SEO_PRO_STAND_TESTS_DIR`: в него войдёт только HTTP-матрица из исторических результатов. Порядок setup/cleanup сохраняется: проверки отсутствия временных страниц выполняются после их удаления. Активная сборка и неизменённый XML 1.8 проверяются перед сценарием; файловый кэш подтверждается по хешам framework/Config и цепочке default+catalog до создания Cache.
