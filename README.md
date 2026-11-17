# Free SEO Pro for OpenCart
Free extension to create clean URLs (also sometimes referred to as RESTful URLs, user-friendly URLs, pretty URLs or search engine-friendly URLs)

[Download extension for OpenCart 3.0.5.1](https://github.com/sh1sh1n/seo_pro/raw/main/seo_pro_oc3.ocmod.zip) (this fork, version 1.8; the archive is built from `seo_pro_oc3.ocmod/`: `install.xml` and `upload/` at the root of the zip)

## Using
1. Make a backup of the files and the database.
2. Make sure the web server rewrites addresses to `index.php?_route_=...`: for Apache rename `.htaccess.txt` from the OpenCart package to `.htaccess`, for nginx add the equivalent rules. The module does not ship its own rules.
3. Install `seo_pro_oc3.ocmod.zip` in Extensions → Installer, then press Refresh in Extensions → Modifications.
4. Go to store settings, on tab Server turn on "Use SEO URLs" field and choose SEO type "SEO Pro". Every store has its own SEO settings, see below.

## Settings of a store
The tab Server of the default store (System → Settings) and of every additional store has four settings:

| Setting | Values | Default |
|---|---|---|
| Use SEO URLs (`config_seo_url`) | Yes / No | No |
| SEO type (`config_seo_url_type`) | Default / SEO Pro | Default |
| SEO with categories (`config_seo_url_include_path`) | Yes / No | No |
| SEO postfix (`config_seo_url_postfix`) | any text, may be empty | empty |

- An additional store that has never been saved since the module was installed uses the settings of the default store. Its form shows these values, and the first save writes them as the own settings of this store. From then on the store does not follow the default store.
- A value that is not allowed is not saved and is not replaced by another one: the whole form is rejected and the field shows an error. The same happens when a record in the database is wrong: choose a correct value and save.
- A form that does not send one of the four settings keeps the value the store has.

## Database changes
On the first request after Refresh (in the admin or on the storefront) the module checks and, when needed, changes the database:

| Function | Change |
|---|---|
| Main category of a product | column `main_category` in `product_to_category` |
| HTML H1 of products, categories, information pages | column `meta_h1 varchar(255) NOT NULL DEFAULT ''` after `meta_title` in `product_description`, `category_description`, `information_description` |
| Descriptions of manufacturers | table `manufacturer_description` (`manufacturer_id`, `language_id`, `description`, `meta_title`, `meta_h1`, `meta_description`, `meta_keyword`, primary key `manufacturer_id, language_id`), with the engine and the charset of `manufacturer` |

- The database user needs ALTER and CREATE for the migration, besides the usual SELECT, INSERT, UPDATE and DELETE. Existing columns and tables are not changed and not dropped: a compatible `meta_h1` (varchar of 255 characters or more, or text) and a compatible `manufacturer_description` (for example of ocStore) are used as they are, a missing text column of the table is added. A column or a table that does not fit (another type, another primary key) is a conflict: it is written to the error log and the function stays off.
- Every function is ready on its own. An error of one function does not turn off the others, and the main category and the SEO addresses keep working. The module tries again on the next request; the schema version `1.7` is written and the module caches are cleared only when every function is ready. After that no check is made: a column removed by hand later is not detected.
- Until the column `main_category` exists the main category of a product is not shown and not saved, and the product path is built from the first category of the product.
- Until H1 of products, categories or information pages is ready, saving and copying of these records in the admin is refused with a message, so that nothing is saved without H1. The storefront shows the name. Until the manufacturer descriptions are ready, adding, editing and deleting manufacturers is refused the same way. Version 1.6.3 saved these records in that case.
- `ALTER TABLE` of large tables can hold queries for a while. Try the upgrade on a copy of the shop and choose the time.
- Uninstalling the module keeps the columns, the table and their content.

If PHP runs with `opcache.validate_timestamps=0`, reset OPcache after Refresh. Otherwise PHP keeps running the old files.

Upgrade from 1.6: the old file `catalog/controller/startup/seo_pro.php` is no longer used and can be deleted.

## HTML H1 and descriptions of manufacturers
- Products, categories and information pages have the field HTML H1 on the tab General of every language, next to Meta Tag Title. A filled H1 replaces `heading_title` on the storefront, an empty one leaves the name (title). `0` is a filled H1. The limit is 255 characters of the stored text (an entity such as `&amp;` counts as five).
- Another modification that already adds `meta_h1` to the descriptions is respected: H1 is added to each INSERT of the descriptions that has none, so an extension that changes only the add or only the edit of a record does not lose H1 on the other one.
- Manufacturers have the tab Description with, for every language, the description (Summernote editor), Meta Tag Title, HTML H1, Meta Tag Description and Meta Tag Keywords. The storefront uses Meta Tag Title for the title (the name when it is empty), the meta tags, H1 for `heading_title` and passes the description to the template as `description`. The limits are 65535 bytes for the description and 255 characters for the other fields.
- A form or an import that does not send H1 keeps the saved H1 of each language it sends; the languages themselves are saved as the core does (a language that is not sent is removed from the record). For manufacturers only the languages and fields that are sent are changed. An empty value clears the field. A value of a wrong type, an invalid UTF-8 text or a too long value rejects the whole save before anything is written.
- The texts are stored the way the core stores a form, escaped once (`"` as `&quot;`, `<` as `&lt;`, `&` as `&amp;`). An import through the model that sends raw text is brought to this form, entities that are already there are kept; the limits count the stored form, so `"` counts as six characters. The HTML of a manufacturer description is stored escaped too and is shown as HTML on the storefront. The admin forms and the storefront (H1, title, meta of manufacturers) escape a raw value that was written to the database by another tool, so it is shown as text.
- The texts are per language, not per store: the descriptions of a manufacturer are the same in every store. A manufacturer without a translation in the current language shows its name and no description. A new language gets an empty H1; the descriptions of manufacturers are not copied to it, and rows of a deleted language stay in `manufacturer_description`.
- Languages of the admin texts: en-gb, ru-ru, uk-ua (a missing language pack is skipped).
- The storefront templates are not changed. H1 works in templates that print `heading_title`. A theme that also uses `heading_title` for `alt`/`title` of images or Open Graph gets H1 there too. The description of a manufacturer is shown only by a template that prints `description`: `manufacturer_info.twig` of theme `default` does not. In OCTemplates Deals the SEO title templates of the theme, when they are turned on in its settings, replace the title and meta description set by the module.
- HTML H1 and the descriptions are not added to the records of blog extensions. The SQL of blog extensions and of the Quickstart (demo import) of Deals is not checked against the new columns. Do not run Quickstart on a shop with data. The addresses of such extensions are a separate matter, see Behaviour.

## Behaviour
- Product links keep all their parameters. The module manages only `product_id` and `path`. Duplicate pages such as `/tyre.html?foo=1` are covered by `rel="canonical"` on the product page.
- Only the configured postfix is removed from the address. With postfix `.html`, the address `/tyre.htm` returns 404.
- The postfix belongs to addresses of products, manufacturers and information pages. Category addresses end with `/` and keep the keyword as it is, so a category keyword may end with the postfix. `/cat.html` is the keyword `cat` with the postfix, `/cat.html/` is the category with the keyword `cat.html`. Only when no keyword `cat` exists, `/cat.html` is looked up as the whole keyword `cat.html`.
- Product paths are built from the table `category_path`. If category paths in links are wrong, press Repair (Rebuild) in Catalog → Categories.
- Links to actions (for example `product/product/write`) do not get the alias of the product, category, manufacturer or information page. A route that has its own keyword in Design → SEO URL, an action route included, gets that keyword, and the keyword opens this route with every method, POST included.
- The module itself opens a record of Design → SEO URL only when its query is `category_id=N`, `product_id=N`, `manufacturer_id=N`, `information_id=N` (N from 1 to 2147483647, without leading zeros) or a route such as `information/contact`. An address is one or more different categories, categories and one product, one product, one manufacturer, one information page or one route. Every other combination of these records answers 404, and parameters in the address (`?product_id=1`, `?route=...`) never choose the page of an SEO address. Addresses `index.php?route=...` work as in the core.
- Records of other extensions (a blog, a theme: for example `blog_id=3`) are left to the router of the core, `catalog/controller/startup/seo_url.php`, with the modifications other extensions have made to it. The module has no list of such extensions and no setting for this:
  - A foreign record is a record with a non-empty query that is none of the kinds above and does not start with `category_id=`, `product_id=`, `manufacturer_id=` or `information_id=`. A wrong record of the module (`product_id=0`, `product_id=1x`) and an empty query are not foreign: they answer 404, also next to a foreign record.
  - An address made of keywords with at least one foreign record is given to `index()` of the core as it was sent, with the postfix and without the parameters of the visitor. It is a page when the core sets a route (`extension/blog/article`). The unmodified core does not read a foreign record, so without a modification that does the address answers 404. The parameters of the visitor are put back under the ones the core has set.
  - A link to a route that is not a page of the module, with parameters other than `path`, is given to `rewrite()` of the core. When the core returns an address, it is used, also when the route has a keyword of its own: the parameters the core has left are written the way the module writes them, and `path` stays a parameter. Otherwise the link is built as before. Addresses built by the core have no postfix and no trailing slash.
  - Products, categories, manufacturers, information pages, the home page and links without parameters are never given to the core.
  - The canonical redirect uses the same links: `index.php?route=extension/blog/article&blog_id=3` is redirected to the address the core builds, and that address opens without a redirect when the extension builds the same address it reads.
  - A modification may read one of its parameters in a link of any route and drop the route, as the core does with `path` (the blog of OCTemplates reads `blog_path` this way). Before a 301 to an address the core has built, the module gives this address back to `index()` of the core and redirects only when it opens the route of the request. So `index.php?route=checkout/cart&blog_path=1` opens the cart and is not redirected to a blog category. Links on a page are not checked this way: a link with such a parameter is the address the extension builds, as in the standard SEO URL mode.
  - The canonical address of a page opened by an address of the core is built from the parameters the core has read in that address; the parameters of the visitor follow as a query string, also the ones with the name of a parameter of the extension. `/article?blog_path=9` opens the article and is not redirected; the parameter reaches the page as in the standard SEO URL mode.
  - For `index.php?route=...` addresses the parameters are the ones of the visitor, in the order sent. An extension that builds an address in the order of the parameters of the link may redirect `...&article_id=1&blog_path=1` to `/article/category`. Such an address opens the same page.
  - Cost. A store without foreign records does not load the router of the core and runs the same queries as 1.7.2. In a store with them, an address with a foreign record costs the queries of the core, one per part of the address, and each different link given to the core costs the queries of the extension, once per request. The unmodified `rewrite()` runs no query for such a link.
  - The store has foreign records when the cached map of keywords of the current store and language has one. After the last foreign record is deleted directly in the database, links are still given to the core until the map is built again.
- The first part of an address that is not a keyword ends the lookup: a long unknown address costs one database query.
- Main category of a product: only a category of the product can be the main one. When none is chosen or the chosen one is removed from the product, the first category becomes the main one. A copy of a product keeps the main category.
- Requests other than GET and HEAD, AJAX requests and the feeds `extension/feed/google_sitemap`, `extension/feed/google_base` and `extension/feed/sitemap_ml` are never redirected. An address made of a route that has a keyword (`/product/search?search=tyre`) is redirected to the keyword with all its parameters.
- The module caches are cleared by exact keys after changes in products, categories, manufacturers, information pages, Design → SEO URL and after Repair in categories, so the file cache, Redis, Mem and Memcached are all supported.
- Records written to `seo_url` past these models (an import, an SEO generator, a blog or theme extension, SQL by hand) are picked up without a cache reset:
  - A new record is seen by the next request: the module reads `MAX(seo_url_id)` once per request and builds the map of keywords again when it has changed. Links get the new keyword at once.
  - A keyword or a query changed in place and a deleted record are seen when an address with the changed keyword is opened: the keywords of every opened SEO address are compared with the table, one query by the index of `keyword`. Before a 301 the keywords of the target are compared too, so the module does not redirect to a keyword that no longer exists.
  - Until such an address is opened or the cache expires, links on other pages keep the old keyword after a change in place or a delete. The first visit to the old keyword answers 404 and the links are correct after it. Clear the cache by hand to skip this.
- The cost is one query `MAX(seo_url_id)` for every request and one query by `keyword` for every request of an SEO address.
- The paths of products and categories are cached in 32 groups by id (`seo_pro.path.p.00` … `seo_pro.path.p.31`, `seo_pro.path.c.00` … `seo_pro.path.c.31`). A new product rewrites only its own group. The cached paths are not compared with the tables: a change of `category_path` or `product_to_category` made directly in the database needs a manual cache reset, see [After an import](#after-an-import).
- `/sitemap.xml` works with or without the rewrite rule for it in `.htaccess`.
- An address or a route sent as an array (`/index.php?route[]=x`, `/index.php?_route_[]=x`) answers 404 without a redirect. An array `route` next to a SEO address is ignored: `/tyre.html?route[]=x` opens the product, and a GET request is redirected to `/tyre.html`.

### After an import
Import modules that write directly to the database do not clear the module caches. New keywords work at once and changed or deleted keywords are noticed when their address is opened (see Behaviour), but links on other pages may keep an old keyword, and changed main categories and category paths stay old until the cache expires (`cache_expire`, one hour by default). Clear the caches after every import:
- In the admin: OpenCart 3.0.5.1 has no button for this cache. The buttons in Developer Settings clear the theme and SASS caches only, Refresh in Extensions → Modifications the modification cache only. Saving any product, category, manufacturer, information page or record of Design → SEO URL clears every module cache.
- File cache (`cache_engine` `file`, the default): delete the files `system/storage/cache/cache.seo_pro*`. Every key of the module starts with `seo_pro`. After an upgrade from 1.7 also delete `cache.product.seopath*` and `cache.category.seopath*`.
- Redis, Mem, Memcached: flush the database of the cache (`FLUSHDB` in Redis, `flush_all` in Memcached). This also clears the core caches and everything else stored in it.

## Limitations
- Cart in theme `default`: after removing a product the cart page reloads, and the checkout page goes to the cart, also when these pages have SEO keywords. Other themes are not changed. In the OCTemplates Deals theme the cart page reloads itself only if the cart keyword is `cart` or `checkout` and the shop is not installed in a subdirectory.

## Upgrade to 1.8
Install the new archive, press Refresh, reset OPcache when `opcache.validate_timestamps=0`. The database is not changed: the schema version stays `1.7` and no migration runs. The cached maps of keywords of 1.7.2 are built again by the first request.

Changes since 1.7.2:
- Records of other extensions in `seo_url` are passed to the router of the core with its modifications, see Behaviour. In 1.6.3 to 1.7.2 such a record answered 404 and the links to its page stayed `index.php?route=...`. An extension that adds its kinds of SEO URL to `catalog/controller/startup/seo_url.php` by OCMOD now works in the mode SEO Pro without changes in the module. An extension that replaces the router in another way is not covered.
- A shop that has such records and such a modification changes its addresses: the keywords open pages, the links get them, and `index.php?route=...` of these pages is redirected to them with a 301. List the records before the upgrade: `php tests/audit_seo_url.php /path/to/shop/config.php` prints them as `foreign`.
- A link to a route that has a keyword and also has parameters is given to the core first in a store with foreign records. The unmodified core returns it unchanged and the link is the same as in 1.7.2.
- A store without foreign records: the same pages, links and queries as in 1.7.2.
- `tests/audit_seo_url.php` tells `foreign` records from `unsupported` ones (wrong records of the module, which still answer 404). The exit code is 1 for both, as before.

## Upgrade to 1.7.2
Install the new archive, press Refresh, reset OPcache when `opcache.validate_timestamps=0`. The database is not changed: the schema version stays `1.7` and no migration runs. The cached maps of keywords of 1.7.1 are built again by the first request.

Changes since 1.7.1:
- Records written to `seo_url` past the models of the core are picked up without a cache reset. Before, a new keyword opened its page with a 301 to `index.php?route=...` until the cache expired (one hour by default), and a changed or deleted keyword kept working.
- Every request costs one more query, `MAX(seo_url_id)`; a request of an SEO address costs one more query by the index of `keyword`. See Behaviour.

## Upgrade to 1.7.1
Install the new archive, press Refresh, reset OPcache when `opcache.validate_timestamps=0`. The database is not changed: the schema version stays `1.7` and no migration runs.

Changes since 1.7:
- `/index.php?route[]=x` wrote the PHP warning `Array to string conversion` to the error log and was redirected to `/index.php?route=Array`; `/index.php?_route_[]=x` answered HTTP 500. Both answer 404 now.
- The paths of products and categories are cached in 32 groups by id. With the file cache delete `cache.product.seopath*` and `cache.category.seopath*` of 1.7, see [After an import](#after-an-import).
- The helpers of the store settings and of the product categories moved from `install.xml` to the library (`system/library/seo_pro_settings.php`, `seo_pro_schema.php`).

## Upgrade to 1.7
1. Back up the files, the database, the installed modifications and the archive of the version in use.
2. Install the new archive, press Refresh, reset OPcache when `opcache.validate_timestamps=0`.
3. Open any page and check the error log: no new line `SEO Pro schema` means every function is ready. Otherwise fix the cause (privileges, a conflicting column or table) and open a page again.

Rollback to 1.6.3: install its archive and press Refresh. H1 and the descriptions stay in the database and are not shown. A later upgrade to 1.7 checks the existing structures again and uses them.

## Upgrade to 1.6.3
Records of Design → SEO URL with another kind of query (for example `blog_id=3` of a blog extension) are not opened by the module itself. In 1.6.3 to 1.7.2 they answer 404; since 1.8 they are passed to the router of the core, see Behaviour. List them, nothing is changed in the database:

```sh
php tests/audit_seo_url.php /path/to/shop/config.php
```

Do not install a version from 1.6.3 to 1.7.2 on a shop that needs such records.

## Compatible
OpenCart 3.0.5.1. Tested on PHP 8.1, 8.3 and 8.5. PHP 8.0 and other OpenCart 3.0.x versions are not tested. The tests run on stubs and on the core files: see `tests/README.md` for what they do not cover.

## Authors

Author: [Yesvik](http://opencartforum.ru/user/6876-yesvik/), 2011
(opencartforum.com, ocStore)

### Contributors

Opencart 3.x

*   [eniarku](https://github.com/eniarku/)

Opencart 2.x

*   [Sergey Ogarkov](https://github.com/ose1955/), <http://www.OpencartJazz.com/>

*   [Ruslan Brest](http://rb.labtodo.com), <http://www.OpencartJazz.com/>

    [rb / rb2](https://opencartforum.com/user/10112-rb2/) (opencartforum.com)



Opencart / ocStore 1.5.x

*   [freelancer](https://opencartforum.com/user/12381-freelancer/)
    (Ruslan Shvarev), <https://github.com/pavlickm/seopro>

*   [cmd](https://opencartforum.com/user/20535-cmd/)
    (Евгений)

*   [rb / rb2](https://opencartforum.com/user/10112-rb2/)
    (Ruslan Brest), <http://rb.labtodo.com>

## Основной адрес товара

Товарные ссылки, созданные штатным `url->link('product/product', ...)`, используют актуальную основную категорию. При включённом пути полный ЧПУ строится только для одной явно отмеченной доступной основной категории с допустимой иерархией. Без единственной основной используется короткий адрес. Настройка отключения пути сохраняется.

Из товарного href и цели нормализации исключаются `manufacturer_id`, `sort`, `order`, `page`, `limit`. Аналитика и неизвестные параметры сохраняются; перед 301 проверяются товар, исходный производитель и старая категорийная цепочка. Правило действует для GET/HEAD, включая XHR. Состав крошек формируют ядро и тема.

Этот контракт рассчитан на новый магазин до накопления публичных URL; изменение адресов действующего наполненного магазина требует отдельной оценки. Подробности, fallback, импорт и ограничения совместимости: [Основной адрес товара](docs/product-navigation.md). Упаковка, установка и номер будущего выпуска выполняются отдельно.
