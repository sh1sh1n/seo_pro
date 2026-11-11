# Free SEO Pro for OpenCart
Free extension to create clean URLs (also sometimes referred to as RESTful URLs, user-friendly URLs, pretty URLs or search engine-friendly URLs)

[Download extension for OpenCart 3.0.5.1](https://github.com/sh1sh1n/seo_pro/raw/main/seo_pro_oc3.ocmod.zip) (this fork, version 1.6.1; the archive is built from `seo_pro_oc3.ocmod/`: `install.xml` and `upload/` at the root of the zip)

## Using
1. Make a backup of the files and the database.
2. Install `seo_pro_oc3.ocmod.zip` in Extensions → Installer, then press Refresh in Extensions → Modifications.
3. Go to store settings, on tab Server turn on "Use SEO URLs" field and choose SEO type "SEO Pro". Every store has its own SEO type.

On the first request after Refresh the module adds the column `main_category` to `product_to_category`. The database user needs the ALTER privilege. Errors are written to the error log, and the module tries again on the next request.

If PHP runs with `opcache.validate_timestamps=0`, reset OPcache after Refresh. Otherwise PHP keeps running the old files.

Upgrade from 1.6: the old file `catalog/controller/startup/seo_pro.php` is no longer used and can be deleted.

## Behaviour
- Product links keep all their parameters. The module manages only `product_id` and `path`. Duplicate pages such as `/tyre.html?foo=1` are covered by `rel="canonical"` on the product page.
- Only the configured postfix is removed from the address. With postfix `.html`, the address `/tyre.htm` returns 404.
- Product paths are built from the table `category_path`. If category paths in links are wrong, press Repair (Rebuild) in Catalog → Categories.
- Links to actions (for example `product/product/write`) are not turned into page addresses.
- POST and other non-GET requests and feeds are never redirected.
- `/sitemap.xml` works with or without the rewrite rule for it in `.htaccess`.

## Limitations
- Cart in theme `default`: after removing a product the page reloads. Other themes are not changed. In the OCTemplates Deals theme the cart page reloads itself only if the cart keyword is `cart` or `checkout` and the shop is not installed in a subdirectory.

## Compatible
OpenCart 3.0.5.1, PHP 8.x. Other 3.0.x versions are not tested.

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
