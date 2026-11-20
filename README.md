# SEO Pro for OpenCart

SEO Pro is an OCMOD extension for OpenCart 3.0.5.1. It replaces the storefront startup router `startup/seo_url` with its own router, `extension/startup/seo_pro`, which builds and decodes SEO URLs (keyword addresses) from the records of Design → SEO URL.

[Download `seo_pro_oc3.ocmod.zip`](https://github.com/sh1sh1n/seo_pro/raw/main/seo_pro_oc3.ocmod.zip) (version 1.8). The archive is built from [`seo_pro_oc3.ocmod/`](seo_pro_oc3.ocmod): `install.xml` and `upload/` are at the root of the zip.

## Installation
1. Back up the files and the database.
2. Make the web server rewrite addresses to `index.php?_route_=...`: for Apache rename `.htaccess.txt` of the OpenCart package to `.htaccess`, for nginx add the equivalent rules. The module does not ship its own rewrite rules.
3. Upload `seo_pro_oc3.ocmod.zip` in Extensions → Installer, then press Refresh in Extensions → Modifications.
4. In the store settings, tab Server, set "Use SEO URLs" to Yes and "SEO type" to "SEO PRO". Every store has its own SEO settings, see below.

If PHP runs with `opcache.validate_timestamps=0`, reset OPcache after Refresh, otherwise PHP keeps running the old files.

## Store settings
The tab Server of the default store (System → Settings) and of every additional store has four settings:

| Setting | Values | Default |
|---|---|---|
| Use SEO URLs (`config_seo_url`) | Yes / No | No |
| SEO type (`config_seo_url_type`) | Default (`seo_url`) / SEO PRO (`seo_pro`) | Default |
| SEO with categories (`config_seo_url_include_path`) | Yes / No | No |
| SEO postfix (`config_seo_url_postfix`) | any text, may be empty | empty |

- With SEO type "Default" the module hands the request to the core router `startup/seo_url`. With "Use SEO URLs" off it does nothing.
- Each setting is taken from the first source that has it: the submitted form, the saved settings of the store, the settings of the default store, the default value above. So an additional store that has not been saved since the module was installed shows and uses the settings of the default store, and its first save writes them as its own.
- A value that is not allowed is not replaced by another source: the form is rejected and the field shows an error. This also applies to a wrong value already stored in the database.
- A form that does not send one of the four settings keeps the value the store has.

## Database changes
On the first request after Refresh (admin or storefront) the module checks the database and, when needed, changes it:

| Function | Change |
|---|---|
| Main category of a product | column `main_category tinyint(1) NOT NULL DEFAULT '0'` after `category_id` in `product_to_category` |
| HTML H1 of products, categories, information pages | column `meta_h1 varchar(255) NOT NULL DEFAULT ''` after `meta_title` in `product_description`, `category_description`, `information_description` |
| Descriptions of manufacturers | table `manufacturer_description` (`manufacturer_id`, `language_id`, `description`, `meta_title`, `meta_h1`, `meta_description`, `meta_keyword`, primary key `manufacturer_id, language_id`), with the engine and collation of the table `manufacturer` |

- The database user needs the ALTER and CREATE privileges in addition to SELECT, INSERT, UPDATE and DELETE.
- The changes are made under the MySQL lock `GET_LOCK('seo_pro_schema', 10)`. Without the lock the request only checks and the next request tries again.
- Existing columns and tables are never changed or dropped. An existing `meta_h1` of type `text`, `mediumtext`, `longtext` or `varchar(255)` or longer is used as it is. An existing `manufacturer_description` with the required key columns and primary key is used, and missing text columns are added to it. A column or table that does not fit is written to the error log (`SEO Pro schema: ...`) and its function stays off.
- Each function is checked on its own: a failure of one does not turn off the others. When every function is ready, the module caches are cleared and the schema version `1.7` is written to the `setting` table. From then on no check is made, so a column removed by hand later is not detected.
- Until the column `main_category` exists, the main category is not shown or saved, and product links get no category path.
- Until H1 of products, categories or information pages is ready, the admin refuses to save or copy these records and shows a warning. Until `manufacturer_description` is ready, saving and deleting manufacturers is refused the same way.
- `ALTER TABLE` on large tables is not covered by the tests. Try the upgrade on a copy of the shop first.
- The module has no uninstall step: the columns, the table and their content stay in the database.

## HTML H1 and manufacturer descriptions
- Products, categories and information pages get an HTML H1 field for every language. A filled H1 replaces `heading_title` on the storefront; an empty one leaves the name. `0` counts as a filled H1. The limit is 255 characters of the stored (escaped) text.
- `meta_h1` is added to every INSERT of the description tables that does not have it yet, so another modification that already adds `meta_h1` to only one of add/edit keeps working.
- Manufacturers get, for every language, a description, Meta Tag Title, HTML H1, Meta Tag Description and Meta Tag Keywords. On the storefront a filled Meta Tag Title, Meta Tag Description and Meta Tag Keywords replace the core values, a filled H1 replaces `heading_title`, and the description is passed to the template as `description`. Limits: 65535 bytes for the description, 255 characters for the other fields.
- A save that does not send `meta_h1` for a language keeps the stored H1 of that language. For manufacturers only the languages and fields that are sent are changed. An empty value clears the field. A wrong type, invalid UTF-8 or a too long value rejects the whole save before anything is written.
- The texts are stored escaped once, the way the core stores form input (`htmlspecialchars` with `ENT_COMPAT`); already present entities are kept, and the length limits apply to this stored form.
- The texts are per language, not per store.
- The admin texts of the module exist for the language packs `en-gb`, `ru-ru` and `uk-ua`; a missing pack is skipped.
- Storefront templates are not changed. H1 appears in templates that print `heading_title`. The manufacturer description appears only in a template that prints `description`; `manufacturer_info.twig` of the theme `default` does not.

## Behaviour
- Product links drop the parameters `manufacturer_id`, `sort`, `order`, `page` and `limit`; other parameters are kept.
- Only the configured postfix is removed from an incoming address. With the postfix `.html`, `/tyre.htm` is not the product `tyre`.
- The postfix is added to addresses of products, manufacturers and information pages. Category addresses end with `/`.
- An incoming address is resolved part by part. The first part that is not a keyword ends the lookup. Only when the address does not resolve part by part is it looked up as one whole keyword, so `/cat.html` is the keyword `cat` with the postfix, and the keyword `cat.html` only when `cat` does not exist.
- The module opens a Design → SEO URL record itself only when its query is `category_id=N`, `product_id=N`, `manufacturer_id=N`, `information_id=N` (N from 1 to 2147483647, without leading zeros) or a route such as `information/contact`. A valid address is: one or more different categories; categories and one product; one product; one manufacturer; one information page; or one route. Any other combination answers 404. Parameters of the visitor (`?product_id=1`, `?route=...`) never choose the page of an SEO address.
- A route that has its own keyword gets that keyword in links. A request to `index.php?route=...` of such a route is redirected to the keyword with its parameters.
- Category paths in category links come from `category_path` and are cached. If they are wrong, press Repair in Catalog → Categories.
- An address or a route sent as an array (`/index.php?route[]=x`, `/index.php?_route_[]=x`) answers 404.
- `/sitemap.xml` opens `extension/feed/sitemap_ml` when that feed is enabled, otherwise `extension/feed/google_sitemap`, with or without a rewrite rule for it.

### Redirects
- A GET or HEAD request whose address differs from the canonical address of the page gets a 301 to it.
- No redirect is sent for other methods, for requests with `X-Requested-With: XMLHttpRequest`, under the CLI SAPI, or for the feeds `extension/feed/google_sitemap`, `extension/feed/google_base` and `extension/feed/sitemap_ml`.
- Product pages are the exception for AJAX: a GET or HEAD request of an outdated product address, XHR included, gets one 301 to the current address. Before it the module checks that the product exists, that a sent `manufacturer_id` is the product's manufacturer and that a sent `path` is a valid category chain; otherwise the request answers 404.

### Main product address
- With "SEO with categories" on, the full address `/category/leaf/product` (plus the postfix) is built only when exactly one category of the product is marked `main_category=1`, that category and all its parents are enabled, assigned to the store and translated into the language, the hierarchy is consistent, and every keyword exists.
- Otherwise the product gets its short address `/product` (plus the postfix) when its keyword exists, or the core address `index.php?route=product/product&product_id=...` (with `path` when a valid main category exists). A partial address that skips a level is never built.
- Link generation never picks a category itself. The admin product form marks the first category as main only when a product is saved with no main category, or with one that is not among its categories. A copied product keeps its main category. Products written past the admin form (an import, SQL) keep whatever mark they have.
- Product address data is read from the database once per request and reused within it, so a consistent change made directly in MySQL is seen by the next request without a cache reset.
- Lists returned by the catalog product model (`getProducts`, `getProductSpecials`, `getLatestProducts`, `getPopularProducts`, `getBestSellerProducts`, `getProductRelated`) pass their product ids to the module, which reads their addresses in one query per 500 ids and, with "SEO with categories" on, their main categories in one more. The links of the list are then built without further queries. A product loaded one by one through `getProduct()` is still read with its own query.

Details, including the parameter rules and the tests: [docs/product-navigation.md](docs/product-navigation.md) (in Russian).

### Records of other extensions
Records of Design → SEO URL written by other extensions (for example `blog_id=3` of a blog) are passed to the core router `catalog/controller/startup/seo_url.php`, including the OCMOD changes other extensions have made to it. The module has no list of such extensions and no setting for this.

- A foreign record is a record with a non-empty query that is not one of the kinds above and does not start with `category_id=`, `product_id=`, `manufacturer_id=` or `information_id=`. A broken record of the module (`product_id=0`, `product_id=1x`) and an empty query are not foreign: they answer 404.
- An incoming address with at least one foreign record is given to `index()` of the core router. It is a page only when the core sets a route. The unmodified core does not read foreign records, so without a modification that does, such an address answers 404.
- In a store with foreign records, a link to a route that is not a product, category, manufacturer or information page, with parameters other than `path`, is first given to `rewrite()` of the core router. When the core returns a different address, it is used. The core result is cached per request.
- Before a 301 to an address built by the core, the module checks that this address opens the route of the request; otherwise it builds the address without the core.
- A store without foreign records does not load the core router.
- To list the records the module does not open itself (it writes nothing; exit code 1 when `foreign` or `unsupported` records exist):

```sh
php tests/audit_seo_url.php /path/to/shop/config.php
```

### Caches
- The map of keywords is cached per store and language. The key `seo_pro.<store_id>.<language_id>` holds the queries of categories, manufacturers, information pages and routes; the keywords are split by `crc32` into 64 groups `seo_pro.<store_id>.<language_id>.k.00` to `.k.63`. A request reads the map and only the groups of the keywords of its address, so its cost does not grow with the number of products. Product links are built from their own records and do not use the map. A group that is missing or belongs to another build of the map makes the request build the whole map again.
- The category paths of category links are cached in 32 groups under the keys `seo_pro.path.c.00` to `seo_pro.path.c.31`.
- Every module key is deleted by its exact name after a product, category, manufacturer or information page is saved through the admin models, after a change in Design → SEO URL and after Repair in Catalog → Categories.
- Records written to `seo_url` past these models (an import, another extension, SQL):
  - A new record is seen by the next request: the cached map stores `MAX(seo_url_id)` and is rebuilt when it changes. This costs one query per request.
  - A keyword or query changed in place, or a deleted record, is detected when an address with that keyword is opened or a redirect target uses it: those keywords are compared with the table in one query. Until then, links on other pages may keep the old keyword.

### After an import
Import tools that write directly to the database do not clear the module caches. To clear them:
- Admin: saving any product, category, manufacturer, information page or Design → SEO URL record clears every module cache.
- File cache (`cache_engine` `file`, the default): delete the files `system/storage/cache/cache.seo_pro*`. After an upgrade from 1.7 also delete `cache.product.seopath*` and `cache.category.seopath*`.
- Redis, Mem, Memcached: flush the cache database (`FLUSHDB` in Redis, `flush_all` in Memcached). This also clears the core caches.

## Limitations
- Theme `default` only: the module adds a script to `checkout/cart.twig` that reloads the cart page after a product is removed, and to `checkout/checkout.twig` that goes to the cart after a removal from the mini cart. Templates of other themes are not changed.
- HTML H1 and descriptions are not added to the records of other extensions (for example blogs).

## Upgrading
- To 1.8 from 1.7.x: install the new archive, press Refresh and reset OPcache when `opcache.validate_timestamps=0`. The schema version stays `1.7`, so no migration runs. Cached keyword maps of older versions, including the single-key map of earlier 1.8 builds, are rebuilt by the first request.
- From 1.6: the old file `catalog/controller/startup/seo_pro.php` is no longer used and can be deleted.
- Shops with records of other extensions in `seo_url` change their addresses with 1.8 when the core router is modified to read those records: see [Records of other extensions](#records-of-other-extensions). Run `tests/audit_seo_url.php` before the upgrade to list them.

## Compatibility and tests
- OpenCart 3.0.5.1. Other OpenCart 3.0.x versions are not tested.
- The tests need the OpenCart 3.0.5.1 sources and `curl`, but no installed shop and no database:

```sh
OC_DIR=/path/to/opencart-3.0.5.1/upload sh tests/run.sh
```

- What the tests cover and do not cover (a real database, Redis/Memcached, a shop in a subdirectory, real third-party SEO extensions, speed): [tests/README.md](tests/README.md).
- Version 1.8 was also checked on an installed test store running [OCTemplates](https://market.octemplates.net/en/) themes. A fresh OpenCart installation was not tested.

## Authors

Author: [Yesvik](http://opencartforum.com/user/6876-yesvik/), 2011 (opencartforum.com, ocStore)

### Contributors

Opencart 3.x

* [eniarku](https://github.com/eniarku/)
* [opencartbot](https://github.com/opencartbot)
