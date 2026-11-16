<?php
// 1.8: records of other extensions in seo_url go to the router of the core, catalog/controller/startup/seo_url.php.
// Served by the PHP built-in server like cases.php, HTTP 500 when a check fails. Two runs, a class is loaded once per request:
//   cases_foreign.php?core=patched  the router of the core changed by foreign.ocmod.xml (SEO_PRO_FOREIGN_DIR, written by ocmod_sim.php)
//   cases_foreign.php?core=stock    the router of the core as it is: no extension reads the foreign records
ob_start();
header('Content-Type: text/plain');
$done = false;
register_shutdown_function(function () use (&$done) { if (!$done) { http_response_code(500); echo "\nFAIL the run did not finish\n"; } });
$run_id = preg_replace('~[^A-Za-z0-9]~', '', (string)getenv('SEO_PRO_RUN_ID'));
$patched = ($_GET['core'] ?? '') === 'patched';
if (!$patched && ($_GET['core'] ?? '') !== 'stock') { echo "usage: cases_foreign.php?core=patched|stock\n"; http_response_code(500); return; }
if ($patched) {
	$dir = rtrim((string)getenv('SEO_PRO_FOREIGN_DIR'), '/');
	if (!is_file($dir . '/catalog/controller/startup/seo_url.php')) { echo "set SEO_PRO_FOREIGN_DIR to the output of ocmod_sim.php with foreign.ocmod.xml\n"; http_response_code(500); return; }
	define('SEO_PRO_MODIFICATION', $dir);
}
require __DIR__ . '/test_seo_pro.php';
echo 'SAPI ' . php_sapi_name() . ', core ' . ($patched ? 'patched' : 'stock') . "\n";

// The records of the synthetic extension, and gallery_id=3: a record no modification reads
$F = [[0,1,'note_id=5','my-note'], [0,1,'note_topic_id=1','guides'], [0,1,'note_topic_id=2','tyres-guide'], [0,1,'extension/notes/topic','notes'],
	[0,1,'extension/notes/offers','offers'], [0,1,'offer_id=3','black-friday'], [0,1,'gallery_id=3','gallery'], [0,2,'note_id=5','zametka']];
$S = 'http://shop.test/';
$plain = fn($route, $args = '') => $S . 'index.php?route=' . $route . ($args === '' ? '' : '&amp;' . $args);
function freq($uri, $method = 'GET', $server = []) { global $F; return run($uri, $method, ['seo' => $F, 'server' => $server]); }
function flink($route, $args = '') { global $F; return link_($route, $args, [], $F); }
function loaded() { return class_exists('ControllerStartupSeoUrl', false); }
function rewrites($r) { $p = new ReflectionProperty('Url', 'rewrite'); if (PHP_VERSION_ID < 80100) $p->setAccessible(true); return array_map('get_class', $p->getValue($r->get('url'))); }

echo "\n-- a store without foreign records: the router of the core is not used\n";
[$x, $r] = run('/moto/enduro/tyre.html');
check('product address: the same queries as in 1.7.2', [$x['route'] ?? $x, sql($r, 'max'), sql($r, 'map'), sql($r, 'verify'), sql($r, 'core'), sql($r, 'all')], ['product/product', 1, 1, 1, 0, 5]);
check('the cached map says that the store has no foreign records', $r->get('cache')->d['seo_pro.0.1']['foreign'] ?? null, false);
$o = ['db' => $r->get('db'), 'cache' => $r->get('cache')];
[$x, $r] = run('/moto/enduro/tyre.html', 'GET', $o);
check('product address, map from the cache: two queries', [$x['route'] ?? $x, sql($r, 'core'), sql($r, 'all')], ['product/product', 0, 2]);
[$x, $r] = run('/contact/?x=1', 'GET', $o); check('route keyword with a parameter: two queries', [$x['route'] ?? $x, sql($r, 'all')], ['information/contact', 2]);
[$x, $r] = run('/index.php?route=product/search&search=a', 'GET', $o); check('redirect to a route keyword: two queries', [$x['redirect'] ?? $x, sql($r, 'all')], [$S . 'search/?search=a', 2]);
[$x, $r] = run('/index.php?route=account/order/info&order_id=7', 'GET', $o); check('route without a keyword: MAX(seo_url_id) only', [$x['route'] ?? $x, sql($r, 'all')], ['account/order/info', 1]);
[$x, $r] = run('/nope/zzz', 'GET', $o); check('unknown address: MAX(seo_url_id) and the lookup of other languages', [$x['route'] ?? $x, sql($r, 'fallback'), sql($r, 'all')], ['error/not_found', 1, 2]);
[$r, $c] = boot($o); $c->index(); $n = sql($r, 'all');
check('links with parameters, with and without a keyword', [$r->get('url')->link('product/search', 'search=a b&tag=x'), $r->get('url')->link('account/order/info', 'order_id=7'), $r->get('url')->link('extension/notes/note', 'note_id=5'), $r->get('url')->link('product/product/write', 'product_id=1')],
	[$S . 'search/?search=a+b&amp;tag=x', $plain('account/order/info', 'order_id=7'), $plain('extension/notes/note', 'note_id=5'), $plain('product/product/write', 'product_id=1')]);
check('these links cost no query', sql($r, 'all'), $n);
check('the router of the core was never loaded', loaded(), false);

echo "\n-- own pages and links in a store with foreign records\n";
check('product, category, manufacturer, information', [flink('product/product', 'product_id=1&option_id=7'), flink('product/category', 'path=11'), flink('product/manufacturer/info', 'manufacturer_id=5'), flink('information/information', 'information_id=4')],
	[$S . 'moto/enduro/tyre.html?option_id=7', $S . 'moto/enduro/', $S . 'heidenau.html', $S . 'about.html']);
check('route keyword with and without parameters, home', [flink('information/contact'), flink('product/search', 'search=a b&category_id=10'), flink('common/home', 'tracking=x')], [$S . 'contact/', $S . 'search/?search=a+b&amp;category_id=10', $S . '?tracking=x']);
check('routes without a keyword keep the plain link', [flink('product/product/write', 'product_id=1'), flink('checkout/cart/remove'), flink('product/product', 'product_id=77&option_id=7')],
	[$plain('product/product/write', 'product_id=1'), $plain('checkout/cart/remove'), $plain('product/product', 'path=10&amp;product_id=77&amp;option_id=7')]);
[$r, $c] = boot(['seo' => $F]); $c->index(); $n = sql($r, 'core');
foreach ([['product/product', 'product_id=1&option_id=7'], ['product/category', 'path=11&page=2'], ['product/manufacturer/info', 'manufacturer_id=5&page=2'], ['information/information', 'information_id=4'], ['information/contact', ''], ['common/home', 'tracking=x'], ['checkout/cart/remove', '']] as [$a, $b]) $r->get('url')->link($a, $b);
check('pages of the module, links without parameters and the home page are not sent to the core', sql($r, 'core') - $n, 0);
check('the cached map says that the store has foreign records', $r->get('cache')->d['seo_pro.0.1']['foreign'] ?? null, true);
[$x, $r] = freq('/moto/enduro/tyre.html?option_id=7'); check('product address: no query of the core', [$x['route'] ?? $x, sql($r, 'core')], ['product/product', 0]);
[$x, $r] = freq('/offers/'); check('route keyword of the extension is a record of the module: no query of the core', [$x['route'] ?? $x, $x['res'] ?? null, sql($r, 'core')], ['extension/notes/offers', 'extension/notes/offers', 0]);
[$x, $r] = freq('/moto/heidenau.html'); check('unsupported combination of own records is still 404, without the core', [$x['route'] ?? $x, sql($r, 'core')], ['error/not_found', 0]);
[$x, $r] = freq('/contact/tyre.html?product_id=1'); check('route and product: still 404', [$x['route'] ?? $x, sql($r, 'core')], ['error/not_found', 0]);

echo "\n-- wrong records of the module are not foreign\n";
[$r, $c] = boot([]);
foreach (['note_id=5', 'gallery_id=3', 'foo=1', 'path=10_11', 'route=product/product', 'product_id[]=1', 'a=b/c', 'a.b/c', '/common/home', 'common/home?x=1'] as $q) check('foreign: ' . json_encode($q), call($c, 'isForeign', $q), true);
foreach (['', 'product_id=0', 'product_id=01', 'product_id=1x', 'product_id=', 'product_id=1&path=10', 'category_id=10&category_id=11', 'manufacturer_id=-1', 'information_id=2147483648', 'product_id=1', 'category_id=10', 'information/contact', null, 5] as $q) check('not foreign: ' . json_encode($q), call($c, 'isForeign', $q), false);
check('a sequence with a wrong record of the module is not sent to the core', [call($c, 'hasForeign', ['note_id=5']), call($c, 'hasForeign', ['category_id=10', 'note_id=5']), call($c, 'hasForeign', ['product_id=01', 'note_id=5']), call($c, 'hasForeign', ['', 'note_id=5']), call($c, 'hasForeign', ['category_id=10'])], [true, true, false, false, false]);
// The unmodified core reads "product_id=01" as a product: such a record next to a foreign one must not reach it
$wrong = [[0,1,'product_id=01','wrong'], [0,1,'','blank']];
foreach (['/wrong/my-note', '/my-note/wrong', '/blank/my-note', '/wrong.html', '/blank/moto/'] as $uri) { [$x, $r] = run($uri, 'GET', ['seo' => array_merge($F, $wrong)]); check("$uri -> 404, the core is not asked", [$x['route'] ?? $x, $x['res'] ?? null, sql($r, 'core')], ['error/not_found', 'error/not_found', 0]); }

if ($patched) {
	echo "\n-- links of the extension\n";
	check('note', flink('extension/notes/note', 'note_id=5'), $S . 'my-note');
	check('note in nested topics, the parameter is topic_path and the records are note_topic_id', flink('extension/notes/note', 'topic_path=1_2&note_id=5'), $S . 'guides/tyres-guide/my-note');
	check('the other parameters are kept and written as the module writes them', flink('extension/notes/note', 'note_id=5&x=a b&f[a]=1&tracking=' . rawurlencode('a&b=c')), $S . 'my-note?x=a+b&amp;f%5Ba%5D=1&amp;tracking=a%26b%3Dc');
	check('topic: asked before the keyword of the route', [flink('extension/notes/topic', 'topic_path=1'), flink('extension/notes/topic', 'topic_path=1_2&page=2')], [$S . 'guides', $S . 'guides/tyres-guide?page=2']);
	check('route of the topics without parameters: the keyword of the route', flink('extension/notes/topic'), $S . 'notes/');
	check('topic without a keyword: the keyword of the route and the parameter', flink('extension/notes/topic', 'topic_path=9'), $S . 'notes/?topic_path=9');
	check('offer after the keyword of the list', [flink('extension/notes/offers'), flink('extension/notes/offer', 'offer_id=3')], [$S . 'offers/', $S . 'offers/black-friday']);
	check('note and offer without a keyword: plain links', [flink('extension/notes/note', 'note_id=6&x=1'), flink('extension/notes/offer', 'offer_id=4')], [$plain('extension/notes/note', 'note_id=6&amp;x=1'), $plain('extension/notes/offer', 'offer_id=4')]);
	check('a record no modification reads: plain link', flink('extension/gallery/info', 'gallery_id=3'), $plain('extension/gallery/info', 'gallery_id=3'));
	// The unmodified rewrite() of the core turns "path" of any route into the keywords of categories
	check('path in a link of another route is not given to the core', [flink('extension/notes/note', 'path=10_11&note_id=5'), flink('extension/notes/note', 'note_id=5&path=10'), flink('account/order/info', 'path=10&order_id=7'), flink('product/search', 'path=10')],
		[$S . 'my-note?path=10_11', $S . 'my-note?path=10', $plain('account/order/info', 'path=10&amp;order_id=7'), $S . 'search/?path=10']);
	check('https link', (function () use ($F) { [$r, $c] = boot(['seo' => $F]); $c->index(); return $r->get('url')->link('extension/notes/note', 'note_id=5', true); })(), 'https://shop.test/my-note');
	[$r, $c] = boot(['seo' => $F]); $c->index(); $n = sql($r, 'core');
	$r->get('url')->link('extension/notes/note', 'topic_path=1_2&note_id=5'); $first = sql($r, 'core') - $n; $r->get('url')->link('extension/notes/note', 'topic_path=1_2&note_id=5');
	check('a link costs the queries of the extension once per request', [$first, sql($r, 'core') - $n], [3, 3]);
	check('links add no rewrite of the core to the url class', rewrites($r), ['ControllerExtensionStartupSeoPro']);

	echo "\n-- addresses of the extension\n";
	[$x, $r] = freq('/my-note'); check('note opens without a redirect', [$x['route'] ?? $x, $x['res'] ?? null, $x['get']['note_id'] ?? null, isset($x['get']['_route_'])], ['extension/notes/note', 'extension/notes/note', '5', false]);
	check('the url class is the one of the shop again, with the rewrite of the module only', [get_class($r->get('url')), rewrites($r)], ['Url', ['ControllerExtensionStartupSeoPro']]);
	[$x, $r] = freq('/guides/tyres-guide/my-note'); check('note in nested topics', [$x['route'] ?? $x, $x['get']['topic_path'] ?? null, $x['get']['note_id'] ?? null], ['extension/notes/note', '1_2', '5']);
	[$x, $r] = freq('/guides/tyres-guide?page=2'); check('nested topics with a parameter', [$x['route'] ?? $x, $x['get']['topic_path'] ?? null, $x['get']['page'] ?? null], ['extension/notes/topic', '1_2', '2']);
	[$x, $r] = freq('/offers/black-friday'); check('a foreign record after a route record', [$x['route'] ?? $x, $x['res'] ?? null, $x['get']['offer_id'] ?? null], ['extension/notes/offer', 'extension/notes/offer', '3']);
	[$x, $r] = freq('/moto/my-note', 'POST'); check('own category and a foreign record: the page the core gives', [$x['route'] ?? $x, $x['get']['path'] ?? null, $x['get']['note_id'] ?? null], ['extension/notes/note', '10', '5']);
	[$x, $r] = freq('/black-friday'); check('offer without the list: the extension gives no route -> 404', [$x['route'] ?? $x, $x['res'] ?? null], ['error/not_found', 'error/not_found']);
	[$x, $r] = freq('/gallery'); check('a record no modification reads -> 404', [$x['route'] ?? $x, $x['res'] ?? null], ['error/not_found', 'error/not_found']);
	[$x, $r] = freq('/my-note/gallery'); check('a read record and an unread one -> 404', $x['route'] ?? $x, 'error/not_found');
	[$x, $r] = freq('/my-note/zzz'); check('unknown part after a foreign record -> 404, the core is not asked', [$x['route'] ?? $x, sql($r, 'core')], ['error/not_found', 0]);
	[$x, $r] = freq('/my-note.html'); check('the postfix is not a part of an address of the core -> 404', $x['route'] ?? $x, 'error/not_found');
	foreach (['GET', 'POST'] as $method) {
		[$x, $r] = freq('/my-note', $method, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']); check("note, XHR $method", $x['route'] ?? $x, 'extension/notes/note');
		[$x, $r] = freq('/gallery?route=extension/notes/note&note_id=5', $method); check("unread record: route and id of the visitor do not open a page ($method)", [$x['route'] ?? $x, $x['res'] ?? null], ['error/not_found', 'error/not_found']);
	}
	[$x, $r] = run('/my-note', 'POST', ['seo' => $F, 'post' => ['text' => 'a']]); check('POST to a note: the page, the body is kept', [$x['route'] ?? $x, $r->get('request')->post], ['extension/notes/note', ['text' => 'a']]);

	echo "\n-- parameters of the visitor do not choose the page\n";
	// The extension opens a note when note_id is set and a topic otherwise: note_id of the visitor must not reach it
	[$x, $r] = freq('/guides?note_id=5'); check('topic address with note_id: the topic, without a redirect, the parameter is kept', [$x['route'] ?? $x, $x['get']['topic_path'] ?? null, $x['get']['note_id'] ?? null], ['extension/notes/topic', '1', '5']);
	[$x, $r] = freq('/guides?note_id=5&route=extension/notes/note', 'POST'); check('route of the visitor does not replace the page (POST)', [$x['route'] ?? $x, $x['res'] ?? null], ['extension/notes/topic', 'extension/notes/topic']);
	[$x, $r] = freq('/guides?route=extension/notes/note'); check('route of the visitor is not in the canonical address', $x, ['redirect' => $S . 'guides', 'status' => 301]);
	[$x, $r] = freq('/my-note?note_id=6', 'POST'); check('the note of the address wins over note_id of the visitor', [$x['route'] ?? $x, $x['get']['note_id'] ?? null], ['extension/notes/note', '5']);
	[$x, $r] = freq('/my-note?note_id=6'); check('note_id of the visitor -> 301 to the note', $x, ['redirect' => $S . 'my-note', 'status' => 301]);
	[$x, $r] = freq('/offers/black-friday?offer_id=4&topic_path=1', 'POST'); check('offer of the address wins, the other parameter is kept', [$x['route'] ?? $x, $x['get']['offer_id'] ?? null, $x['get']['topic_path'] ?? null], ['extension/notes/offer', '3', '1']);
	[$x, $r] = freq('/black-friday?route=extension/notes/offers', 'POST'); check('route of the visitor does not complete an address', $x['route'] ?? $x, 'error/not_found');

	echo "\n-- canonical redirects\n";
	foreach (['/index.php?route=extension/notes/note&note_id=5' => 'my-note', '/index.php?route=extension/notes/note&topic_path=1_2&note_id=5&x=a%26b&f%5Ba%5D=1' => 'guides/tyres-guide/my-note?x=a%26b&f%5Ba%5D=1',
		'/index.php?route=extension/notes/topic&topic_path=1' => 'guides', '/index.php?route=extension/notes/offer&offer_id=3' => 'offers/black-friday', '/guides/' => 'guides', '/my-note/?x=1' => 'my-note?x=1',
		'/zametka' => 'my-note', '/moto/my-note' => 'my-note?path=10', '/index.php?route=extension/notes/topic&topic_path=9' => 'notes/?topic_path=9'] as $uri => $location) {
		check("301: $uri", freq($uri)[0], ['redirect' => $S . $location, 'status' => 301]);
		[$n, $x] = follow($uri, ['seo' => $F]); check('one 301, then the address is stable', [$n, isset($x['route'])], [1, true]);
	}
	foreach (['/my-note', '/my-note?x=a%20b&f%5Ba%5D=1', '/my-note?x=a+b', '/guides/tyres-guide/my-note?tracking=a%26b%3Dc', '/guides', '/guides?page=2', '/offers/black-friday', '/offers/', '/notes/', '/notes/?topic_path=9', '/my-note?path=10'] as $uri) {
		[$n, $x] = follow($uri, ['seo' => $F]); check("stable without 301: $uri", [$n, isset($x['route']) && $x['route'] !== 'error/not_found'], [0, true]);
	}
	foreach (['/index.php?route=extension/notes/note&note_id=6', '/index.php?route=extension/gallery/info&gallery_id=3'] as $uri) { [$n, $x] = follow($uri, ['seo' => $F]); check("no keyword, no redirect: $uri", [$n, isset($x['route'])], [0, true]); }
	foreach (['POST', 'PUT', 'DELETE'] as $method) { [$x] = freq('/index.php?route=extension/notes/note&note_id=5', $method); check("$method plain address of a note: no 301", [$x['route'] ?? $x, $x['get']['note_id'] ?? null], ['extension/notes/note', '5']); }
	[$x] = freq('/index.php?route=extension/notes/note&note_id=5', 'GET', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']); check('XHR plain address of a note: no 301', $x['route'] ?? $x, 'extension/notes/note');
	[$x] = freq('/index.php?route=extension/notes/note&note_id=5', 'HEAD'); check('HEAD plain address of a note: 301', $x['status'] ?? $x, 301);
	[$x] = freq('/index.php?route=extension/feed/google_base&note_id=5'); check('feed: no 301', $x['route'] ?? $x, 'extension/feed/google_base');

	echo "\n-- a parameter the extension reads in a link of any route does not send the visitor to another page\n";
	// rewrite() of the extension turns topic_path of any route into the keywords of topics and drops the route, as blog extensions do
	foreach (['/index.php?route=checkout/cart&topic_path=1', '/index.php?route=account/order/info&order_id=7&topic_path=1_2', '/index.php?route=product/product/review&product_id=1&topic_path=1',
		'/index.php?route=extension/notes/offers&topic_path=1' => 'offers/?topic_path=1', '/index.php?route=product/search&search=a&topic_path=1' => 'search/?search=a&topic_path=1', '/search/?search=a&topic_path=1',
		'/offers/?topic_path=1', '/moto/?topic_path=1', '/moto/enduro/tyre.html?topic_path=1', '/?topic_path=1', '/index.php?route=checkout/cart&topic_path=9'] as $uri => $location) {
		if (is_int($uri)) { $uri = $location; [$n, $x] = follow($uri, ['seo' => $F]); check("no redirect: $uri", [$n, isset($x['route']) && $x['route'] !== 'error/not_found' && strpos($x['route'], 'extension/notes/') !== 0 || $x['route'] === 'extension/notes/offers'], [0, true]); continue; }
		check("301 to the keyword of the route, not to a topic: $uri", freq($uri)[0], ['redirect' => $S . $location, 'status' => 301]);
		[$n, $x] = follow($uri, ['seo' => $F]); check('one 301, then the address is stable', [$n, isset($x['route'])], [1, true]);
	}
	[$x] = freq('/index.php?route=checkout/cart&topic_path=1'); check('the route and the parameter are kept', [$x['route'] ?? $x, $x['get']['topic_path'] ?? null], ['checkout/cart', '1']);
	check('the pages of the extension are still redirected to their keywords', [freq('/index.php?route=extension/notes/topic&topic_path=1_2')[0], freq('/index.php?route=extension/notes/note&topic_path=1&note_id=5')[0]],
		[['redirect' => $S . 'guides/tyres-guide', 'status' => 301], ['redirect' => $S . 'guides/my-note', 'status' => 301]]);
	// The extension writes the parts of an address in the order of the parameters of the link
	foreach (['/guides/tyres-guide/my-note?note_id=5&topic_path=1', '/guides/tyres-guide/my-note?note_id=6&topic_path=9&x=1' => '?x=1', '/guides/tyres-guide/my-note?topic_path=1&note_id=5'] as $uri => $rest) {
		if (is_int($uri)) { $uri = $rest; $rest = ''; }
		check("parameters of the visitor do not reorder the address: $uri", freq($uri)[0], ['redirect' => $S . 'guides/tyres-guide/my-note' . $rest, 'status' => 301]);
	}
	// A parameter of the visitor with the name of a parameter of the extension stays a parameter of the query string
	foreach (['/my-note?topic_path=9' => ['9', '5'], '/my-note?topic_path=1' => ['1', '5'], '/guides?note_id=6' => ['1', '6'], '/my-note?x=1&topic_path=1_2' => ['1_2', '5']] as $uri => $exp) {
		[$x] = freq($uri); check("no redirect, the parameter is kept: $uri", [$x['get']['topic_path'] ?? $x, $x['get']['note_id'] ?? null], $exp);
	}
	foreach (['/my-note?note_id=5&topic_path=1' => 'my-note?topic_path=1', '/my-note?topic_path=9&note_id=6' => 'my-note?topic_path=9', '/guides/my-note?topic_path=9&x=1' => 'guides/my-note?x=1',
		'/my-note/?topic_path=9' => 'my-note?topic_path=9', '/moto/my-note?topic_path=1' => 'my-note?path=10&topic_path=1'] as $uri => $location) {
		check("301: $uri", freq($uri)[0], ['redirect' => $S . $location, 'status' => 301]);
		[$n, $x] = follow($uri, ['seo' => $F]); check('one 301, then the address is stable', [$n, $x['route'] ?? $x], [1, 'extension/notes/note']);
	}
	// The core reads a foreign record and a product in one address and opens the product: the module builds its address
	foreach (['/black-friday/tyre' => 'moto/enduro/tyre.html?offer_id=3', '/black-friday/moto' => 'moto/?offer_id=3', '/black-friday/tyre?x=1&path=10' => 'moto/enduro/tyre.html?offer_id=3&x=1'] as $uri => $location) {
		check("301: $uri", freq($uri)[0], ['redirect' => $S . $location, 'status' => 301]);
		[$n, $x] = follow($uri, ['seo' => $F]); check('one 301, then the address is stable', [$n, isset($x['route']) && strpos($x['route'], 'product/') === 0], [1, true]);
	}
	[$x, $r] = freq('/index.php?route=checkout/cart&topic_path=1_2'); $n = sql($r, 'core');
	check('the check costs the queries of the core for the parts of the address it has built', [$x['route'] ?? $x, $n], ['checkout/cart', 4]);
	[$x, $r] = freq('/index.php?route=checkout/cart&order_id=7'); check('a link the core does not change costs no query', [$x['route'] ?? $x, sql($r, 'core')], ['checkout/cart', 0]);

	echo "\n-- queries\n";
	[, $r] = freq('/moto/'); $o = ['db' => $r->get('db'), 'cache' => $r->get('cache')];
	[$x, $r] = run('/my-note', 'GET', $o);
	check('note, map from the cache: MAX(seo_url_id), the check of the map, one query of the core for the address and one for the canonical link', [$x['route'] ?? $x, sql($r, 'max'), sql($r, 'verify'), sql($r, 'map'), sql($r, 'fallback'), sql($r, 'core'), sql($r, 'all')], ['extension/notes/note', 1, 1, 0, 0, 2, 4]);
	[$x, $r] = run('/guides/tyres-guide/my-note', 'GET', $o);
	check('three parts: three queries for the address, three for the link', [sql($r, 'core'), sql($r, 'all')], [6, 8]);
	[$x, $r] = run('/moto/enduro/tyre.html', 'GET', $o); check('product address in this store: two queries, as without foreign records', [sql($r, 'core'), sql($r, 'all')], [0, 2]);

	echo "\n-- the map follows foreign records written past the models of the core\n";
	function fwarm($seo) { [, $r] = run('/moto/', 'GET', ['seo' => $seo]); return ['db' => $r->get('db'), 'cache' => $r->get('cache')]; }
	$o = fwarm([]); $o['db']->seo[] = [0,1,'note_id=5','my-note'];
	[$x, $r] = run('/my-note', 'GET', $o);
	check('INSERT of the first foreign record: its address opens without a redirect', [$x['route'] ?? $x, $x['get']['note_id'] ?? null, sql($r, 'map'), $o['cache']->d['seo_pro.0.1']['foreign']], ['extension/notes/note', '5', 1, true]);
	$o = fwarm([]); $o['db']->seo[] = [0,1,'note_id=5','my-note'];
	[$r, $c] = boot($o); $c->index();
	check('INSERT: links get the keyword at once', $r->get('url')->link('extension/notes/note', 'note_id=5'), $S . 'my-note');
	check('INSERT: the plain address is redirected to it', run('/index.php?route=extension/notes/note&note_id=5', 'GET', $o)[0], ['redirect' => $S . 'my-note', 'status' => 301]);

	$o = fwarm($F); $o['db']->seo[row($o['db'], 'my-note')][3] = 'new-note';
	[$x, $r] = run('/new-note', 'GET', $o);
	check('UPDATE of a keyword: the new keyword opens without a redirect', [$x['route'] ?? $x, $x['get']['note_id'] ?? null, sql($r, 'map')], ['extension/notes/note', '5', 1]);
	$o = fwarm($F); $o['db']->seo[row($o['db'], 'my-note')][3] = 'new-note';
	[$x, $r] = run('/my-note', 'GET', $o);
	check('UPDATE of a keyword: the old keyword is 404, the map is built again, the core is not asked', [$x['route'] ?? $x, sql($r, 'verify'), sql($r, 'map'), sql($r, 'core')], ['error/not_found', 1, 1, 0]);
	$o = fwarm($F); $o['db']->seo[row($o['db'], 'my-note')][3] = 'new-note';
	check('UPDATE of a keyword: the plain address goes to the new keyword', run('/index.php?route=extension/notes/note&note_id=5', 'GET', $o)[0], ['redirect' => $S . 'new-note', 'status' => 301]);
	$o = fwarm($F); $o['db']->seo[row($o['db'], 'guides')][2] = 'note_topic_id=7';
	[$x, $r] = run('/guides', 'GET', $o);
	check('UPDATE of a query: the keyword opens the new page', [$x['route'] ?? $x, $x['get']['topic_path'] ?? null, sql($r, 'map')], ['extension/notes/topic', '7', 1]);
	$o = fwarm($F); $o['db']->seo[row($o['db'], 'about')][2] = 'note_id=8';
	[$x, $r] = run('/about', 'GET', $o);
	check('UPDATE of an own record to a foreign one: the keyword opens the page of the extension', [$x['route'] ?? $x, $x['get']['note_id'] ?? null], ['extension/notes/note', '8']);
	$o = fwarm($F); $o['db']->seo[row($o['db'], 'my-note')][2] = 'information_id=4';
	[$x, $r] = run('/my-note.html', 'GET', $o);
	check('UPDATE of a foreign record to an own one: the module opens it, without the core', [$x['route'] ?? $x, $x['get']['information_id'] ?? null, sql($r, 'core')], ['information/information', '4', 0]);

	$o = fwarm($F); unset($o['db']->seo[row($o['db'], 'my-note')]);
	[$x, $r] = run('/my-note', 'GET', $o);
	check('DELETE: the keyword is 404, the map is built again', [$x['route'] ?? $x, sql($r, 'map'), sql($r, 'core')], ['error/not_found', 1, 0]);
	$o = fwarm($F); unset($o['db']->seo[row($o['db'], 'my-note')]);
	[$x, $r] = run('/index.php?route=extension/notes/note&note_id=5', 'GET', $o);
	check('DELETE: the plain address is not redirected to the deleted keyword', [$x['route'] ?? $x, $x['get']['note_id'] ?? null], ['extension/notes/note', '5']);
	$o = fwarm($F); unset($o['db']->seo[row($o['db'], 'guides')]);
	[$x, $r] = run('/guides/tyres-guide/my-note', 'GET', $o);
	check('DELETE of a topic keyword: the address with it is 404', $x['route'] ?? $x, 'error/not_found');

	$o = fwarm($F); $map = $o['cache']->d['seo_pro.0.1']; unset($map['foreign']); $o['cache']->d['seo_pro.0.1'] = $map;
	[$x, $r] = run('/my-note', 'GET', $o);
	check('a map cached by 1.7.2 is built again', [$x['route'] ?? $x, sql($r, 'map'), $o['cache']->d['seo_pro.0.1']['foreign'] ?? null], ['extension/notes/note', 1, true]);
	[$x, $r] = run('/my-note', 'GET', $o);
	check('unchanged table: the map is not built again', [sql($r, 'map'), sql($r, 'max'), sql($r, 'verify')], [0, 1, 1]);
} else {
	echo "\n-- no modification of the router of the core: foreign records are not pages\n";
	foreach (['/my-note', '/guides', '/guides/tyres-guide/my-note', '/offers/black-friday', '/moto/my-note', '/gallery', '/my-note.html', '/zametka'] as $uri) {
		foreach (['GET', 'POST'] as $method) { [$x, $r] = freq($uri . '?route=extension/notes/note&note_id=5&product_id=1', $method); check("$uri -> 404 ($method)", [$x['route'] ?? $x, $x['res'] ?? null, isset($x['get']['_route_'])], ['error/not_found', 'error/not_found', false]); }
	}
	[$x, $r] = freq('/guides/tyres-guide/my-note'); check('the core is asked once per part, the url class gets no rewrite of the core', [sql($r, 'core'), get_class($r->get('url')), rewrites($r)], [3, 'Url', ['ControllerExtensionStartupSeoPro']]);
	check('links of the extension stay plain', [flink('extension/notes/note', 'note_id=5'), flink('extension/notes/note', 'topic_path=1_2&note_id=5&x=a b'), flink('extension/notes/offer', 'offer_id=3')],
		[$plain('extension/notes/note', 'note_id=5'), $plain('extension/notes/note', 'topic_path=1_2&amp;note_id=5&amp;x=a+b'), $plain('extension/notes/offer', 'offer_id=3')]);
	check('a route with a keyword keeps it', [flink('extension/notes/topic'), flink('extension/notes/topic', 'topic_path=1'), flink('extension/notes/offers')], [$S . 'notes/', $S . 'notes/?topic_path=1', $S . 'offers/']);
	[$r, $c] = boot(['seo' => $F]); $c->index(); $n = sql($r, 'all');
	foreach ([['extension/notes/note', 'note_id=5'], ['account/order/info', 'order_id=7'], ['product/search', 'search=a'], ['product/product/write', 'product_id=1']] as [$a, $b]) $r->get('url')->link($a, $b);
	$r->get('url')->link('account/order/info', 'path=10&order_id=7'); $r->get('url')->link('extension/notes/note', 'path=10');
	check('links to routes that are not pages of the module cost no query', sql($r, 'all') - $n, 0);
	check('path in a link of another route stays a parameter', [flink('account/order/info', 'path=10&order_id=7'), flink('product/search', 'path=10')], [$plain('account/order/info', 'path=10&amp;order_id=7'), $S . 'search/?path=10']);
	foreach (['/index.php?route=extension/notes/note&note_id=5', '/notes/?topic_path=1', '/offers/'] as $uri) { [$n, $x] = follow($uri, ['seo' => $F]); check("no redirect: $uri", [$n, isset($x['route']) && $x['route'] !== 'error/not_found'], [0, true]); }
	[$x, $r] = freq('/index.php?route=extension/notes/topic&topic_path=1'); check('plain address of a route with a keyword -> the keyword', $x, ['redirect' => $S . 'notes/?topic_path=1', 'status' => 301]);
}

echo "\nnotices: {$GLOBALS['notices']}, failed: $fail\n";
if ($fail > 0 || $GLOBALS['notices'] > 0) http_response_code(500);
else echo "seo_pro cases finished $run_id\n";
$done = true;
