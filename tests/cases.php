<?php
// Served by the PHP built-in server. The answer is HTTP 200 only when every check passed without PHP warnings,
// otherwise HTTP 500: run it with "curl -f".
ob_start();
header('Content-Type: text/plain');
$done = false;
register_shutdown_function(function () use (&$done) { if (!$done) { http_response_code(500); echo "\nFAIL the run did not finish\n"; } });
// run.sh checks that the answer comes from the server it has started, and from this run
$run_id = preg_replace('~[^A-Za-z0-9]~', '', (string)getenv('SEO_PRO_RUN_ID'));
if (isset($_GET['ping'])) { $done = true; echo "seo_pro cases ready $run_id\n"; return; }
require __DIR__ . '/test_seo_pro.php';
echo "SAPI " . php_sapi_name() . "\n\n-- links\n";
check('product with path', link_('product/product', 'path=10&product_id=1'), 'http://shop.test/moto/enduro/tyre.html');
check('product, path taken from main category', link_('product/product', 'product_id=1'), 'http://shop.test/moto/enduro/tyre.html');
check('product without path setting', link_('product/product', 'path=10&product_id=1', ['config_seo_url_include_path' => 0]), 'http://shop.test/tyre.html');
check('product keeps all params (F03)', link_('product/product', 'product_id=1&option_id=7&popup=1'), 'http://shop.test/moto/enduro/tyre.html?option_id=7&amp;popup=1');
check('product without alias keeps params', link_('product/product', 'product_id=77&option_id=7'), 'http://shop.test/index.php?route=product/product&amp;path=10&amp;product_id=77&amp;option_id=7');
check('product without alias, no path setting', link_('product/product', 'product_id=77&option_id=7', ['config_seo_url_include_path' => 0]), 'http://shop.test/index.php?route=product/product&amp;product_id=77&amp;option_id=7');
check('category nested', link_('product/category', 'path=11'), 'http://shop.test/moto/enduro/');
check('category 12 levels (F11)', link_('product/category', 'path=111'), 'http://shop.test/' . implode('/', array_map(fn($i) => "c$i", range(100, 111))) . '/');
check('product in 12-level category', link_('product/product', 'product_id=9'), 'http://shop.test/' . implode('/', array_map(fn($i) => "c$i", range(100, 111))) . '/deep.html');
check('manufacturer', link_('product/manufacturer/info', 'manufacturer_id=5'), 'http://shop.test/heidenau.html');
check('information', link_('information/information', 'information_id=4'), 'http://shop.test/about.html');
check('route keyword', link_('information/contact'), 'http://shop.test/contact/');
check('search keeps params', link_('product/search', 'search=a b&category_id=10'), 'http://shop.test/search/?search=a+b&amp;category_id=10');
check('action route stays (F05)', link_('product/product/write', 'product_id=1'), 'http://shop.test/index.php?route=product/product/write&amp;product_id=1');
check('cart remove stays', link_('checkout/cart/remove'), 'http://shop.test/index.php?route=checkout/cart/remove');
check('home', link_('common/home'), 'http://shop.test/');
check('home tracking %26 (F04)', link_('common/home', 'tracking=' . rawurlencode('a&b=c')), 'http://shop.test/?tracking=a%26b%3Dc');
check('product tracking %26 (F04)', link_('product/product', 'product_id=1&tracking=' . rawurlencode('a&b=c')), 'http://shop.test/moto/enduro/tyre.html?tracking=a%26b%3Dc');
check('dot keyword link', link_('product/product', 'product_id=2', ['config_seo_url_include_path' => 0]), 'http://shop.test/road.tyre.html');
check('duplicate keyword: second product plain', link_('product/product', 'product_id=6', ['config_seo_url_include_path' => 0]), 'http://shop.test/index.php?route=product/product&amp;product_id=6');
[$r, $c] = boot([]); $c->index();
check('rewrite no query', $c->rewrite('http://shop.test/'), 'http://shop.test/');
check('rewrite scheme-relative', $c->rewrite('//shop.test/index.php?route=information/contact'), 'http://shop.test/contact/');
check('rewrite empty', $c->rewrite(''), '');
check('url->link("")', $r->get('url')->link(''), 'http://shop.test/index.php?route=');

echo "\n-- decode\n";
$x = req('/moto/enduro/tyre.html'); check('product page', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/product', '10_11']);
$x = req('/road.tyre.html', 'GET', ['config_seo_url_include_path' => 0]); check('dot keyword decodes (F07)', [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '2']);
$x = req('/tyre.htm', 'GET', ['config_seo_url_include_path' => 0]); check('other extension -> 404', $x['route'] ?? $x, 'error/not_found');
$x = req('/moto/enduro/'); check('category page', $x['route'] ?? $x, 'product/category');
$x = req('/heidenau.html'); check('manufacturer page', $x['route'] ?? $x, 'product/manufacturer/info');
$x = req('/contact/'); check('contact page', $x['route'] ?? $x, 'information/contact');
$x = req('/information/contact'); check('route address: one 301 to absolute', $x, ['redirect' => 'http://shop.test/contact/', 'status' => 301]);
$x = req('/information/contact', 'POST'); check('POST to route address: no 301, route kept', $x['route'] ?? $x, 'information/contact');
$x = req('/index.php?route=product/product&product_id=1'); check('old address -> 301 to SEO', $x, ['redirect' => 'http://shop.test/moto/enduro/tyre.html', 'status' => 301]);
$x = req('/index.php?route=product/product&product_id=1', 'POST'); check('POST old address: no 301', $x['route'] ?? $x, 'product/product');
$x = req('/index.php?route=product/product&product_id=1', 'HEAD'); check('HEAD old address: 301', $x['status'] ?? $x, 301);
$x = req('/moto/enduro/tyre.html?tracking=a%26b%3Dc'); check('tracking %26 opens without 301', $x['route'] ?? $x, 'product/product');
$x = req('/moto/enduro/tyre.html?option_id=7'); check('extra product param: no 301 (canonical)', $x['route'] ?? $x, 'product/product');
$x = req('/search/?search=a%20b'); check('space as %20: no 301', $x['route'] ?? $x, 'product/search');
$x = req('/search/?search=a+b'); check('space as +: no 301', $x['route'] ?? $x, 'product/search');
$x = req('/moto/?page=0'); check('page=0 -> 301', $x, ['redirect' => 'http://shop.test/moto/', 'status' => 301]);
$x = req('/moto/?page=-1'); check('page=-1 -> 301', $x['redirect'] ?? $x, 'http://shop.test/moto/');
$x = req('/moto/?page=2'); check('page=2 stays', $x['route'] ?? $x, 'product/category');
$x = req('/foo.html'); check('keyword of store 1 in store 0 -> 404 (multistore)', $x['route'] ?? $x, 'error/not_found');
$x = req('/shina.html', 'GET', ['config_seo_url_include_path' => 0]); check('keyword of other language: found, 301 to current language', $x, ['redirect' => 'http://shop.test/tyre.html', 'status' => 301]);
$x = req('/sitemap.xml'); check('sitemap via _route_ (F15)', $x['res'] ?? $x, 'extension/feed/google_sitemap');
$x = req('/sitemap.xml', 'GET', ['feed_sitemap_ml_status' => 1]); check('sitemap_ml via _route_', $x['res'] ?? $x, 'extension/feed/sitemap_ml');
$x = req('/index.php?route=extension/feed/google_base'); check('feed: no 301', $x['route'] ?? $x, 'extension/feed/google_base');
$x = req('/tyre.html', 'GET', ['config_seo_url_include_path' => 0]); check('duplicate keyword opens first product', $x['get']['product_id'] ?? $x, '1');
$x = req('/'); check('home', $x['route'] ?? $x, 'common/home');
$x = req('/?tracking=a%26b%3Dc'); check('home tracking no 301', $x['route'] ?? $x, 'common/home');

echo "\n-- 1.6.2 F02: route address goes through validate()\n";
$x = req('/product/search?search=tyre'); check('route address keeps params in 301', $x, ['redirect' => 'http://shop.test/search/?search=tyre', 'status' => 301]);
$x = req('/product/search?search=a%26b&category_id=10'); check('route address keeps encoded params', $x, ['redirect' => 'http://shop.test/search/?search=a%26b&category_id=10', 'status' => 301]);
$x = req('/product/search?search=tyre', 'HEAD'); check('route address HEAD: 301', $x['status'] ?? $x, 301);
$x = req('/product/search?search=tyre', 'GET', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']); check('route address XHR: no 301', [$x['route'] ?? $x, $x['get']['search'] ?? null], ['product/search', 'tyre']);
$x = req('/product/search?search=tyre', 'POST'); check('route address POST: no 301', $x['route'] ?? $x, 'product/search');
$x = req('/extension/feed/google_sitemap'); check('feed route address with keyword: no 301', [$x['route'] ?? $x, $x['res'] ?? null], ['extension/feed/google_sitemap', 'extension/feed/google_sitemap']);
$x = req('/gsitemap/'); check('feed keyword opens feed', $x['route'] ?? $x, 'extension/feed/google_sitemap');
$x = req('/information/contact'); check('contact: still one 301 to absolute', $x, ['redirect' => 'http://shop.test/contact/', 'status' => 301]);
$x = req('/contact/'); check('contact keyword: no second 301', $x['route'] ?? $x, 'information/contact');

echo "\n-- 1.6.2 F03: category keyword ending with the postfix\n";
check('link category cat', link_('product/category', 'path=30'), 'http://shop.test/cat/');
check('link category cat.html', link_('product/category', 'path=31'), 'http://shop.test/cat.html/');
check('link nested sub.html', link_('product/category', 'path=31_32'), 'http://shop.test/cat.html/sub.html/');
$x = req('/cat/'); check('decode /cat/', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '30']);
$x = req('/cat.html/'); check('decode /cat.html/', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '31']);
$x = req('/cat.html/sub.html/'); check('decode /cat.html/sub.html/', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '31_32']);
$x = req('/cat.html/sub.html/?page=2'); check('nested with page', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '31_32']);
$x = req('/cat.html'); check('/cat.html is keyword cat + postfix -> 301 to /cat/', $x, ['redirect' => 'http://shop.test/cat/', 'status' => 301]);
$np = ['config_seo_url_include_path' => 0];
check('link product box', link_('product/product', 'product_id=41', $np), 'http://shop.test/box.html');
check('link category box.html', link_('product/category', 'path=33'), 'http://shop.test/box.html/');
$x = req('/box.html', 'GET', $np); check('/box.html opens product box, not category box.html', [$x['route'] ?? $x, $x['get']['product_id'] ?? null, isset($x['get']['path'])], ['product/product', '41', false]);
$x = req('/box.html/', 'GET', $np); check('/box.html/ opens category box.html', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '33']);
$x = req('/legacy.html', 'GET', $np); check('whole-address keyword still found when nothing else matches', $x, ['redirect' => 'http://shop.test/legacy.html.html', 'status' => 301]);
$x = req('/cat'); check('/cat without slash -> 301 to /cat/', $x, ['redirect' => 'http://shop.test/cat/', 'status' => 301]);
$x = req('/moto/enduro/'); check('nested categories still decode', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '10_11']);
$x = req('/moto/enduro/tyre.html'); check('product in categories still decodes', [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '1']);
$x = req('/road.tyre.html', 'GET', ['config_seo_url_include_path' => 0]); check('road.tyre.html still decodes', $x['get']['product_id'] ?? $x, '2');
$x = req('/tyre.htm', 'GET', ['config_seo_url_include_path' => 0]); check('/tyre.htm still 404', $x['route'] ?? $x, 'error/not_found');
$x = req('/tyre.html/', 'GET', ['config_seo_url_include_path' => 0]); check('/tyre.html/ (postfix + slash) -> 404', $x['route'] ?? $x, 'error/not_found');
$x = req('/heidenau.html'); check('manufacturer still decodes', $x['route'] ?? $x, 'product/manufacturer/info');

echo "\n-- 1.6.2 A01: schema not ready\n";
// getPathByProduct() keeps a static map for the request: each check takes a product id no other case has used
[$r, $c] = boot(['config' => ['seo_pro_schema_failed' => true]]); $c->index(); $r->get('db')->p2c[88] = 11;
check('schema failed: link built from the first category', $r->get('url')->link('product/product', 'product_id=88'), 'http://shop.test/index.php?route=product/product&amp;path=10_11&amp;product_id=88');
check('schema failed: product_to_category is read', count(preg_grep('~product_to_category WHERE product_id = .88. LIMIT 1~', $r->get('db')->log)), 1);
check('schema failed: no main_category in SQL', count(preg_grep('~main_category~', $r->get('db')->log)), 0);
[$r, $c] = boot([]); $c->index(); $r->get('db')->p2c[89] = 11; $r->get('url')->link('product/product', 'product_id=89');
check('schema ready: main_category in SQL', count(preg_grep('~product_id = .89. ORDER BY main_category DESC LIMIT 1~', $r->get('db')->log)), 1);

echo "\n-- type switch (F02)\n";
[$r, $c] = boot(['config' => ['config_seo_url_type' => 'seo_url']]);
$res = $c->index();
check('seo_url type delegates', [$res, $r->get('load')->calls], ['delegated:startup/seo_url', ['startup/seo_url']]);
check('seo_url type: no seo_url queries', count($r->get('db')->log), 0);
[$r, $c] = boot(['config' => ['config_seo_url_type' => null]]);
check('missing type delegates', $c->index(), 'delegated:startup/seo_url');

echo "\n-- 1.6.3 F1: lookup methods, the search stops at the first unknown part\n";
[$x, $r] = run('/' . implode('/', array_map(fn($i) => "u$i", range(1, 2000))));
check('2000 unknown parts -> 404', $x['route'] ?? $x, 'error/not_found');
check('2000 unknown parts: one fallback query and one map load', [sql($r, 'fallback'), sql($r, 'map'), sql($r, 'all')], [1, 1, 2]);
[$x, $r] = run('/moto/unknown/' . implode('/', array_map(fn($i) => "x$i", range(1, 500))));
check('known category, unknown part, long tail -> 404', $x['route'] ?? $x, 'error/not_found');
check('the tail costs no queries', [sql($r, 'fallback'), sql($r, 'all')], [1, 2]);
[$x, $r] = run('/moto//enduro/'); check('empty inner part -> 404 without a query', [$x['route'] ?? $x, sql($r, 'fallback')], ['error/not_found', 0]);
$whole = [[0,1,'information_id=7','docs/terms']];
[$x, $r] = run('/docs/terms', 'POST', ['seo' => $whole]);
check('unknown path that is a whole-address keyword still opens', [$x['route'] ?? $x, $x['get']['information_id'] ?? null, sql($r, 'fallback')], ['information/information', '7', 1]);
[$r, $c] = boot([]); $c->index();
check('keyword -> query, current language', call($c, 'getQueryByKeyword', 'moto'), 'category_id=10');
check('keyword -> query, other language needs the fallback', [call($c, 'getQueryByKeyword', 'shina'), call($c, 'getQueryByKeyword', 'shina', true)], [null, 'product_id=1']);
check('keyword -> query, unknown is null', call($c, 'getQueryByKeyword', 'nope', true), null);
check('duplicate keyword belongs to the first query', [call($c, 'getQueryByKeyword', 'tyre'), call($c, 'getKeywordByQuery', 'product_id=1'), call($c, 'getKeywordByQuery', 'product_id=6')], ['product_id=1', 'tyre', null]);
check('query -> keyword and back', call($c, 'getQueryByKeyword', call($c, 'getKeywordByQuery', 'information/contact')), 'information/contact');
check('query -> keyword knows only the current language', call($c, 'getKeywordByQuery', 'product_id=3'), null);
[$r, $c] = boot(['seo' => [[0,1,'','blank'], [0,1,'information_id=8','']]]); $c->index();
check('empty query is a string, not null', call($c, 'getQueryByKeyword', 'blank'), '');
check('empty keyword is a string, not null', call($c, 'getKeywordByQuery', 'information_id=8'), '');
[$r, $c] = boot([]); $c->index(); $before = sql($r, 'all');
foreach ([['product/product', 'product_id=1'], ['product/category', 'path=11'], ['information/contact', ''], ['product/product', 'product_id=1'], ['product/product', 'product_id=77'], ['information/information', 'information_id=99']] as [$a, $b]) $r->get('url')->link($a, $b);
check('links: no seo_url query for an alias', [sql($r, 'map'), sql($r, 'fallback')], [1, 0]);
$n = sql($r, 'all'); $r->get('url')->link('product/product', 'product_id=1'); $r->get('url')->link('product/category', 'path=11');
check('repeated path: no new query', sql($r, 'all'), $n);
check('links do not add headers and keep the session', [$r->get('response')->headers, $r->get('session')->data], [[], ['language' => 'en-gb']]);
$np = ['config_seo_url_include_path' => 0];
[$r, $c] = boot(['config' => $np]); $c->index();
check('store 0 map', [$r->get('url')->link('product/product', 'product_id=3'), $r->get('url')->link('product/product', 'product_id=2')], ['http://shop.test/index.php?route=product/product&amp;product_id=3', 'http://shop.test/road.tyre.html']);
$r->get('config')->d['config_store_id'] = 1;
check('store 1 gets its own map', [$r->get('url')->link('product/product', 'product_id=3'), $r->get('url')->link('product/product', 'product_id=2'), sql($r, 'map')], ['http://shop.test/foo.html', 'http://shop.test/index.php?route=product/product&amp;product_id=2', 2]);
$r->get('config')->d['config_store_id'] = 0; $r->get('config')->d['config_language_id'] = 2;
check('language 2 gets its own map', [$r->get('url')->link('product/product', 'product_id=1'), sql($r, 'map')], ['http://shop.test/shina.html', 3]);
check('each map has its own cache key', array_keys($r->get('cache')->d), ['seo_pro.0.1', 'seo_pro.1.1', 'seo_pro.0.2']);

echo "\n-- 1.6.3 F2: the kinds of query\n";
[$r, $c] = boot([]);
foreach (['category_id=10' => 'category_id', 'product_id=1' => 'product_id', 'manufacturer_id=5' => 'manufacturer_id', 'information_id=4' => 'information_id', 'product_id=2147483647' => 'product_id',
	'common/home' => 'route', 'product/product/write' => 'route', 'extension/module/example' => 'route', 'Account_2/x' => 'route'] as $q => $type) check("valid: $q", call($c, 'classifyQuery', $q)['type'] ?? null, $type);
$bad = ['product_id=0', 'product_id=01', 'product_id=-1', 'product_id=+1', 'product_id=1x', 'product_id=', 'product_id=1.0', 'product_id=1e3', 'product_id= 1', 'product_id=1 ', "product_id=1\n", 'product_id=2147483648', 'product_id=99999999999',
	'product_id[]=1', 'foo=1', 'path=10_11', 'route=product/product', 'product_id=1&path=10', 'category_id=10&category_id=11', '', '/common/home', 'common/home/', 'common//home', 'common/home?x=1', 'common/home&x=1', 'a.b/c', 'a\\b', 'a=b/c', 'a b', "common/home\n"];
foreach ($bad as $q) check('invalid: ' . json_encode($q), call($c, 'classifyQuery', $q), null);
check('invalid: not a string', [call($c, 'classifyQuery', null), call($c, 'classifyQuery', ['product_id=1']), call($c, 'classifyQuery', 1)], [null, null, null]);

echo "\n-- 1.6.3 F2: sequences\n";
$x = req('/moto/enduro/'); check('categories', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '10_11']);
$x = req('/tyre.html', 'GET', $np); check('product', [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '1']);
$x = req('/moto/enduro/tyre.html'); check('categories and product', [$x['route'] ?? $x, $x['get']['path'] ?? null, $x['get']['product_id'] ?? null], ['product/product', '10_11', '1']);
$x = req('/heidenau.html'); check('manufacturer', [$x['route'] ?? $x, $x['get']['manufacturer_id'] ?? null], ['product/manufacturer/info', '5']);
$x = req('/about.html'); check('information', [$x['route'] ?? $x, $x['get']['information_id'] ?? null], ['information/information', '4']);
$x = req('/contact/'); check('route keyword', [$x['route'] ?? $x, $x['res'] ?? null], ['information/contact', 'information/contact']);
foreach (['/tyre/moto/' => 'category after product', '/tyre/road.tyre.html' => 'two products', '/moto/heidenau.html' => 'manufacturer after category', '/moto/about.html' => 'information after category',
	'/heidenau/tyre.html' => 'product after manufacturer', '/about/heidenau.html' => 'two entities', '/contact/tyre.html' => 'route and product', '/moto/contact/' => 'category and route',
	'/contact/search/' => 'two routes', '/moto/moto/' => 'the same category twice', '/moto/enduro/moto/' => 'the same category again'] as $uri => $name) {
	foreach (['GET', 'POST'] as $method) { $x = req($uri . '?product_id=1&path=10', $method); check("$name -> 404 ($method)", $x['route'] ?? $x, 'error/not_found'); }
}
$rows = []; foreach ($bad as $i => $q) $rows[] = [0, 1, $q, "bad$i"];
foreach ($bad as $i => $q) {
	[$x, $r] = run("/bad$i.html?product_id=1&information_id=4", 'GET', ['seo' => $rows, 'config' => $np]);
	check('record ' . json_encode($q) . ' -> 404, ids of the visitor do not open a page', [$x['route'] ?? $x, $x['res'] ?? null], ['error/not_found', 'error/not_found']);
}
$x = req('/max.html', 'GET', $np, [], [[0,1,'product_id=2147483647','max']]); check('largest id opens', [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '2147483647']);
$x = req('/moto/zzz/?product_id=1'); check('unknown part after a category -> 404 with product_id', $x['route'] ?? $x, 'error/not_found');
$x = req('/moto/enduro/tyre.html/zzz?product_id=1'); check('unknown tail after a product -> 404', $x['route'] ?? $x, 'error/not_found');
$x = req('/zzz?route=product/product&product_id=1'); check('unknown address with GET route -> 404', [$x['route'] ?? $x, $x['res'] ?? null], ['error/not_found', 'error/not_found']);
$x = req('/zzz?manufacturer_id=5'); check('unknown address with manufacturer_id -> 404', $x['route'] ?? $x, 'error/not_found');
$x = req('/zzz?information_id=4', 'POST'); check('unknown address with information_id, POST -> 404', $x['route'] ?? $x, 'error/not_found');
$x = req('/tyre.html?route=account/login', 'POST', $np); check('GET route does not replace the page', [$x['route'] ?? $x, $x['res'] ?? null], ['product/product', 'product/product']);
$x = req('/tyre.html?route=account/login', 'GET', $np); check('GET route is not in the canonical address', $x, ['redirect' => 'http://shop.test/tyre.html', 'status' => 301]);
$x = req('/about.html?product_id=1'); check('product_id of the visitor does not replace the information page', [$x['route'] ?? $x, $x['get']['information_id'] ?? null, $x['get']['product_id'] ?? null], ['information/information', '4', '1']);
$x = req('/heidenau.html?product_id=1&path=10', 'POST'); check('ids of the visitor do not replace the manufacturer', [$x['route'] ?? $x, $x['get']['manufacturer_id'] ?? null], ['product/manufacturer/info', '5']);
$x = req('/moto/?path=99', 'POST'); check('path of the address wins over the path of the visitor', [$x['route'] ?? $x, $x['get']['path'] ?? null], ['product/category', '10']);
$x = req('/tyre.html?product_id=2', 'POST', $np); check('product of the address wins over product_id of the visitor', [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '1']);
$x = req('/moto/?path=99'); check('path of the visitor -> 301 to the category', $x, ['redirect' => 'http://shop.test/moto/', 'status' => 301]);
$x = req('/shina.html', 'POST', $np); check('keyword of other language opens the product', [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '1']);
$x = req('/docs/terms', 'GET', [], [], $whole); check('whole-address keyword with "/" is one record', $x['redirect'] ?? $x, 'http://shop.test/docs%2Fterms.html');
$x = req('/index.php?route=foo/bar&product_id=1&x=1'); check('index.php?route= is not restricted', [$x['route'] ?? $x, $x['get']['x'] ?? null], ['foo/bar', '1']);
$x = req('/index.php?route=product/product/write&product_id=1'); check('index.php action route keeps working', $x['route'] ?? $x, 'product/product/write');

echo "\n-- 1.6.3 F2: an action route with a keyword\n";
$write = [[0,1,'product/product/write','write']];
check('link to the action', link_('product/product/write', 'product_id=1', [], $write), 'http://shop.test/write/?product_id=1');
[$x, $r] = run('/write/?product_id=1', 'POST', ['seo' => $write, 'post' => ['name' => 'A', 'text' => str_repeat('t', 30), 'rating' => '5']]);
check('POST to the keyword opens the action, not the product', [$x['route'] ?? $x, $x['res'] ?? null, $x['get']['product_id'] ?? null], ['product/product/write', 'product/product/write', '1']);
check('POST body is kept', $r->get('request')->post, ['name' => 'A', 'text' => str_repeat('t', 30), 'rating' => '5']);
$x = req('/write/?product_id=1', 'GET', [], [], $write); check('GET to the keyword: no 301', $x['route'] ?? $x, 'product/product/write');
$x = req('/write/?product_id=1', 'GET', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], $write); check('XHR to the keyword', $x['route'] ?? $x, 'product/product/write');
$x = req('/product/product/write?product_id=1', 'POST', [], [], $write); check('route address with product_id, POST: the action', [$x['route'] ?? $x, $x['res'] ?? null], ['product/product/write', 'product/product/write']);
$x = req('/product/product/write?product_id=1', 'GET', [], [], $write); check('route address with product_id, GET: 301 to the keyword', $x, ['redirect' => 'http://shop.test/write/?product_id=1', 'status' => 301]);
$x = req('/product/product/write?product_id=1', 'POST'); check('route address without a keyword -> 404, product_id does not open the product', $x['route'] ?? $x, 'error/not_found');
$x = req('/product/product/write?product_id=1&route=product/product', 'POST'); check('GET route does not open it either', $x['route'] ?? $x, 'error/not_found');
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
	$x = req('/index.php?route=product/product&product_id=1', $method); check("$method old address: no 301", [$x['route'] ?? $x, $x['get']['product_id'] ?? null], ['product/product', '1']);
	$x = req('/information/contact', $method); check("$method route address: no 301", $x['route'] ?? $x, 'information/contact');
}
foreach (['GET', 'HEAD'] as $method) { $x = req('/index.php?route=product/product&product_id=1', $method); check("$method old address: 301", $x['status'] ?? $x, 301); }
$x = req('/index.php?route=product/product&product_id=1', 'GET', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']); check('XHR old address: no 301', $x['route'] ?? $x, 'product/product');

echo "\n-- 1.6.3 F5: names of parameters in the canonical address\n";
foreach (['/?a%26b=1' => ['a&amp;b' => '1'], '/?a%22b=1' => ['a&quot;b' => '1'], '/?a%3Cb%3E=1' => ['a&lt;b&gt;' => '1'], '/?%26amp%3Bx=1' => ['&amp;amp;x' => '1'], '/?%D1%8F=%D0%B6' => ['я' => 'ж'],
	'/?f%5Ba%26b%5D%5Bc%22d%5D=1' => ['f' => ['a&amp;b' => ['c&quot;d' => '1']]], '/?tracking=x&option_id=7' => ['tracking' => 'x', 'option_id' => '7'],
	'/?q=a%26b' => ['q' => 'a&amp;b'], '/?q=%26amp%3B' => ['q' => '&amp;amp;'], '/?q=a%20b%2Bc' => ['q' => 'a b+c'], '/moto/?a%26b=1&page=2' => ['a&amp;b' => '1', 'page' => '2'],
	'/moto/enduro/tyre.html?a%26b%3Dc=d%26e' => ['a&amp;b=c' => 'd&amp;e'], '/search/?search=a%26b&f%5Bx%26y%5D=1' => ['search' => 'a&amp;b', 'f' => ['x&amp;y' => '1']]] as $uri => $exp) {
	[$n, $x] = follow($uri);
	check("stable without 301: $uri", [$n, params($x)], [0, $exp]);
}
// PHP itself renames these parameters: one 301 to the name PHP uses, then the address is stable
foreach (['/?a%20b=1' => ['a_b' => '1'], '/?f%5B%5D=1&f%5B%5D=2' => ['f' => ['1', '2']]] as $uri => $exp) { [$n, $x] = follow($uri); check("stable after one 301: $uri", [$n, params($x)], [1, $exp]); }
foreach (['/index.php?route=product/category&path=10&a%26b=1' => ['http://shop.test/moto/?a%26b=1', ['a&amp;b' => '1']],
	'/index.php?route=information/contact&f%5Ba%26b%5D%5Bc%22d%5D=1&q=%26amp%3B' => ['http://shop.test/contact/?f%5Ba%26b%5D%5Bc%22d%5D=1&q=%26amp%3B', ['f' => ['a&amp;b' => ['c&quot;d' => '1']], 'q' => '&amp;amp;']],
	'/index.php?route=common/home&%26amp%3Bx=%D0%B6' => ['http://shop.test/?%26amp%3Bx=%D0%B6', ['&amp;amp;x' => 'ж']],
	'/index.php?route=product/product&product_id=1&tracking=a%26b&option_id=7' => ['http://shop.test/moto/enduro/tyre.html?tracking=a%26b&option_id=7', ['tracking' => 'a&amp;b', 'option_id' => '7']]] as $uri => [$location, $exp]) {
	check("301: $uri", req($uri), ['redirect' => $location, 'status' => 301]);
	[$n, $x] = follow($uri);
	check('one 301, then the address is stable and the parameters are the same', [$n, params($x)], [1, $exp]);
}

echo "\nnotices: {$GLOBALS['notices']}, failed: $fail\n";
if ($fail > 0 || $GLOBALS['notices'] > 0) http_response_code(500);
else echo "seo_pro cases finished $run_id\n";
$done = true;
