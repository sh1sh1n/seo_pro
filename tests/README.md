# Tests

No OpenCart installation and no database are needed. The tests need the sources of OpenCart 3.0.5.1 (`Url`, `Request`, `utf8`, Twig, the admin controllers, models, templates and language files) and `curl`.

```sh
OC_DIR=/path/to/opencart-3.0.5.1/upload sh tests/run.sh
```

`PHP=/path/to/php` chooses the PHP binary, `PORT=8765` the port of the built-in server. The script prints `PASS` or `FAIL` for each part and the failed checks. The exit code is 0 only when every part passed. The server is stopped when the script ends.

## Parts

| Part | Command | Fails with |
|---|---|---|
| OCMOD: `install.xml` applied to the core the way Refresh does | `php tests/ocmod_sim.php --check=install.xml <core with upload/> <out dir> $OC_DIR/system/modification.xml seo_pro_oc3.ocmod/install.xml` | exit code 1 |
| Controller: links, decoding, redirects | `curl -f http://127.0.0.1:8765/cases.php` under `OC_DIR=... php -S 127.0.0.1:8765 -t tests` | HTTP 500 |
| Schema installer | `php tests/test_schema.php` | exit code 1 |
| Cache resets | `php tests/test_cache.php <out dir>` | exit code 1 |
| Main category of a product: add, edit, copy | `php tests/test_product_categories.php <out dir>` | exit code 1 |
| `audit_seo_url.php --tsv`: wrong input is never a clean result | `php tests/test_audit.php` | exit code 1 |
| SEO settings of the default store and of additional stores | `OC_DIR=... php tests/test_store_settings.php <out dir>` | exit code 1 |

- `<out dir>` holds the core files changed by `ocmod_sim.php`. Three tests run these files, so they test the code the shop will run.
- `ocmod_sim.php` ends with `SUMMARY <json>`. An operation whose search is not found and a missing file are failures. Language packs other than `en-gb` are optional: the core without `uk-ua` passes, the files are listed in `missing_optional_files`. Without `--check` every XML on the command line decides the exit code.
- `cases.php` runs under the built-in server because the module does not redirect under the CLI SAPI. The answer is HTTP 500 when a check fails, PHP reports a warning or the run does not finish. Always call it with `curl -f`.
- `run.sh` passes the controller part only when the PHP server it has started is alive, answers with the id of this run and the answer ends with the line `seo_pro cases finished <id>`. When the port is in use the part fails, also when the other server answers HTTP 200. By hand, check the last line of the answer yourself: HTTP 200 alone does not prove that `cases.php` was executed.
- `audit_seo_url.php <config.php of the shop>` is not a test. It reads `seo_url` of a shop and lists the records the module does not open. It writes nothing. Exit code 0: every record is supported; 1: unsupported records exist; 2: the check could not run or the input could not be read in full. With `--tsv <file>` a BOM, CRLF, empty lines and a header line are accepted, any other line that is not five tab-separated fields with numeric ids stops the check.

## What the tests do not cover

- A real database: the first run of the schema installer in two requests at once, a database user without the ALTER privilege, the composite primary key of `product_to_category`, the `store_id` of the saved settings.
- The admin forms in a browser. The settings forms are rendered by Twig from the changed templates and checked as HTML, the product form is not rendered.
- Redis, Mem and Memcached: the cache is a stub that deletes by exact key.
- A shop in a subdirectory, a port other than 80 and 443, themes other than `default`, other modifications.
- Speed and memory.
- PHP versions other than the ones the script was run on.
