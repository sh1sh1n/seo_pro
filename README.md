# Free SEO Pro for OpenCart
Free extension to create clean URLs (also sometimes referred to as RESTful URLs, user-friendly URLs, pretty URLs or search engine-friendly URLs)

[Download extension for OpenCart 3.0.5.1](https://github.com/sh1sh1n/seo_pro/raw/main/seo_pro_oc3.ocmod.zip) (this fork, version 1.6.3; the archive is built from `seo_pro_oc3.ocmod/`: `install.xml` and `upload/` at the root of the zip)

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

On the first request after Refresh the module adds the column `main_category` to `product_to_category`. The database user needs the ALTER privilege. Errors are written to the error log, and the module tries again on the next request. Until the column exists the shop keeps working: the main category of a product is not shown and not saved, and the product path is built from the first category of the product.

If PHP runs with `opcache.validate_timestamps=0`, reset OPcache after Refresh. Otherwise PHP keeps running the old files.

Upgrade from 1.6: the old file `catalog/controller/startup/seo_pro.php` is no longer used and can be deleted.

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
