# Free SEO Pro for OpenCart
Free extension to create clean URLs (also sometimes referred to as RESTful URLs, user-friendly URLs, pretty URLs or search engine-friendly URLs)

[Download extension for OpenCart 3.0.5.1](https://github.com/sh1sh1n/seo_pro/raw/main/seo_pro_oc3.ocmod.zip) (this fork, version 1.7; the archive is built from `seo_pro_oc3.ocmod/`: `install.xml` and `upload/` at the root of the zip)

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
- Every function is ready on its own. An error of one function does not turn off the others, and the main category and the SEO addresses keep working. The module tries again on the next request; the version `1.7` is written and the module caches are cleared only when every function is ready. After that no check is made: a column removed by hand later is not detected.
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
- Blog extensions and the Quickstart (demo import) of Deals are not supported: their SQL is not checked against the new columns. Do not run Quickstart on a shop with data.

## Behaviour
- Product links keep all their parameters. The module manages only `product_id` and `path`. Duplicate pages such as `/tyre.html?foo=1` are covered by `rel="canonical"` on the product page.
- Only the configured postfix is removed from the address. With postfix `.html`, the address `/tyre.htm` returns 404.
- The postfix belongs to addresses of products, manufacturers and information pages. Category addresses end with `/` and keep the keyword as it is, so a category keyword may end with the postfix. `/cat.html` is the keyword `cat` with the postfix, `/cat.html/` is the category with the keyword `cat.html`. Only when no keyword `cat` exists, `/cat.html` is looked up as the whole keyword `cat.html`.
- Product paths are built from the table `category_path`. If category paths in links are wrong, press Repair (Rebuild) in Catalog → Categories.
- Links to actions (for example `product/product/write`) do not get the alias of the product, category, manufacturer or information page. A route that has its own keyword in Design → SEO URL, an action route included, gets that keyword, and the keyword opens this route with every method, POST included.
- The module opens a record of Design → SEO URL only when its query is `category_id=N`, `product_id=N`, `manufacturer_id=N`, `information_id=N` (N from 1 to 2147483647, without leading zeros) or a route such as `information/contact`. An address is one or more different categories, categories and one product, one product, one manufacturer, one information page or one route. Every other record or combination answers 404, and parameters in the address (`?product_id=1`, `?route=...`) never choose the page of an SEO address. Addresses `index.php?route=...` work as in the core.
- The first part of an address that is not a keyword ends the lookup: a long unknown address costs one database query.
- Main category of a product: only a category of the product can be the main one. When none is chosen or the chosen one is removed from the product, the first category becomes the main one. A copy of a product keeps the main category.
- Requests other than GET and HEAD, AJAX requests and the feeds `extension/feed/google_sitemap`, `extension/feed/google_base` and `extension/feed/sitemap_ml` are never redirected. An address made of a route that has a keyword (`/product/search?search=tyre`) is redirected to the keyword with all its parameters.
- The module caches are cleared by exact keys after changes in products, categories, manufacturers, information pages, Design → SEO URL and after Repair in categories, so the file cache, Redis, Mem and Memcached are all supported. Changes made directly in the database need a manual cache reset.
- `/sitemap.xml` works with or without the rewrite rule for it in `.htaccess`.

## Limitations
- Cart in theme `default`: after removing a product the cart page reloads, and the checkout page goes to the cart, also when these pages have SEO keywords. Other themes are not changed. In the OCTemplates Deals theme the cart page reloads itself only if the cart keyword is `cart` or `checkout` and the shop is not installed in a subdirectory.

## Upgrade to 1.7
1. Back up the files, the database, the installed modifications and the archive of the version in use.
2. Install the new archive, press Refresh, reset OPcache when `opcache.validate_timestamps=0`.
3. Open any page and check the error log: no new line `SEO Pro schema` means every function is ready. Otherwise fix the cause (privileges, a conflicting column or table) and open a page again.

Rollback to 1.6.3: install its archive and press Refresh. H1 and the descriptions stay in the database and are not shown. A later upgrade to 1.7 checks the existing structures again and uses them.

## Upgrade to 1.6.3
Records of Design → SEO URL with another kind of query (for example `blog_id=3` of a blog extension) are no longer opened by the module. Before the upgrade list them, nothing is changed in the database:

```sh
php tests/audit_seo_url.php /path/to/shop/config.php
```

Do not install this version on a shop that needs such records.

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
