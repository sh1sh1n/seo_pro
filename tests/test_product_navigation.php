<?php
require __DIR__ . '/test_seo_pro.php';
function nav($opts = []) { [$r, $c] = boot($opts); $r->get('url')->addRewrite($c); return [$r, $c]; }
function href($r, $id, $extras = []) { return $r->get('url')->link('product/product', ['product_id' => $id] + $extras); }
if (in_array('--address-data', $argv ?? [], true)) {
    [$r, $c] = nav();
    check('current product keyword and explicit main', call($c, 'readProductAddressData', 1), ['keyword' => 'tyre', 'main_category_id' => 11]);
    check('real hierarchy', call($c, 'readProductCategoryData', 11), ['valid' => true, 'path' => '10_11', 'keywords' => ['moto', 'enduro']]);
    $r->get('db')->seo[] = [0,1,'product_id=1','',900];
    [$r, $c] = nav(['db' => $r->get('db')]);
    check('empty last alias distinct from null', call($c, 'readProductAddressData', 1)['keyword'], '');
    check('negative result memoized', call($c, 'readProductAddressData', 888)['keyword'], null);
    $n = count($r->get('db')->log); call($c, 'readProductAddressData', 888); check('negative adds no SQL', count($r->get('db')->log), $n);
    echo "notices: $notices, failed: $fail\n"; exit($fail || $notices ? 1 : 0);
}
[$r, $c] = nav(); $before = $r->get('request')->get;
check('general product href', href($r, 1, ['manufacturer_id' => 5, 'sort' => 'p.price', 'order' => 'ASC', 'page' => 2, 'limit' => 20, 'utm_source' => 'a&b', 'custom' => ['x' => 'a+b']]), 'http://shop.test/moto/enduro/tyre.html?utm_source=a%26b&amp;custom%5Bx%5D=a%2Bb');
check('generation leaves Request intact', $r->get('request')->get, $before);
check('generation never calls model', $r->get('model_catalog_product')->calls, 0);
check('no map in product generation', sql($r, 'map'), 0);
foreach ([
    'category' => ['path'=>10,'page'=>2],
    'manufacturer' => ['manufacturer_id'=>5,'sort'=>'p.price','order'=>'ASC','page'=>2],
    'search/tag' => ['search'=>'tyre','tag'=>'offroad','page'=>3],
    'featured module' => [],
    'related products' => ['path'=>'10_11']
] as $origin => $extras) {
    [$r,$c]=nav(); $expected='http://shop.test/moto/enduro/tyre.html';
    if ($origin==='search/tag') $expected.='?search=tyre&amp;tag=offroad';
    check('stock generator input: '.$origin,href($r,1,$extras),$expected);
}
foreach (['none' => [], 'multiple' => [10 => 1, 11 => 1]] as $name => $marks) {
    [$r, $c] = nav(); $r->get('db')->main[1] = $marks;
    check($name . ' main: short', href($r, 1), 'http://shop.test/tyre.html');
}
[$r, $c] = nav(); $r->get('db')->categories[10]['status'] = 0;
check('unavailable ancestor: short', href($r, 1), 'http://shop.test/tyre.html');
[$r, $c] = nav(); unset($r->get('db')->seo[2]);
check('missing ancestor keyword: structural fallback', href($r, 1), 'http://shop.test/index.php?route=product/product&amp;path=10_11&amp;product_id=1');
[$r, $c] = nav(); $r->get('db')->seo[] = [0,1,'product_id=1','',900];
check('empty last alias is selected', href($r, 1), 'http://shop.test/index.php?route=product/product&amp;path=10_11&amp;product_id=1');
foreach ([0,1,3] as $k) {
    [$r, $c] = nav(); $db = $r->get('db');
    for ($i = 200; $i < 220; $i++) { $db->seo[] = [0,1,'product_id=' . $i, 'p' . $i, 1000 + $i]; $db->main[$i] = $k ? [10 + ($i % $k) => 1] : []; }
    $db->paths[12] = '12'; $db->categories[12] = ['parent_id' => 0, 'status' => 1, 'store' => 1, 'language' => 1]; $db->seo[] = [0,1,'category_id=12','third',2000];
    for ($i = 200; $i < 220; $i++) href($r, $i);
    check("20 products K=$k address SQL", sql($r, 'product_address') + sql($r, 'category_address'), 20 + $k);
    check("20 products K=$k metadata", sql($r, 'schema_metadata'), 1);
    check("20 products K=$k all SQL includes only address and metadata", sql($r, 'all'), 21 + $k);
    $count = count($db->log); for ($i = 200; $i < 220; $i++) href($r, $i);
    check("20 products K=$k repeat SQL", count($db->log) - $count, 0);
}

foreach (['product_id=90','category_id=90','manufacturer_id=90','information_id=90','product/search','foreign_id=90','product_id=bad'] as $owner) {
    [$r, $c] = nav(); $r->get('db')->seo = [[0,1,$owner,'SAME',1],[0,1,'product_id=1','same',2],[0,1,'category_id=10','moto',3],[0,1,'category_id=11','enduro',4]];
    check('blocked product alias: ' . $owner, call($c,'readProductAddressData',1)['keyword'], null);
    check('first owner ' . $owner, href($r,1), 'http://shop.test/index.php?route=product/product&amp;path=10_11&amp;product_id=1');
}
[$r, $c] = nav(); $db = $r->get('db'); $db->seo[] = [0,1,'product_id=1','new-name',100]; $db->seo[] = [0,1,'product_id=1','tyre',101];
check('last unblocked alias; duplicate does not replace', href($r,1), 'http://shop.test/moto/enduro/new-name.html');
[$r, $c] = nav(['config' => ['seo_pro_schema_failed' => true]]);
check('known missing main column: short URL', href($r,1), 'http://shop.test/tyre.html');
check('missing main column: no pc JOIN', count(preg_grep('/JOIN oc_product_to_category/', $r->get('db')->log)), 0);
foreach (['cycle' => '11_10', 'missing root' => '11', 'missing ancestor' => '999_11'] as $label => $path) {
    [$r, $c] = nav(); $r->get('db')->paths[11] = $path;
    check($label . ' hierarchy short fallback', href($r,1), 'http://shop.test/tyre.html');
}
[$r, $c] = nav(['config' => ['config_seo_url_include_path' => 0]]);
check('path setting off: no hierarchy SQL', href($r,1), 'http://shop.test/tyre.html'); check('path off K=0', sql($r,'category_address'), 0);
[$r, $c] = nav(); $db = $r->get('db'); $cache = $r->get('cache'); href($r,1);
$db->main[1] = [10 => 1]; $db->seo[2][3] = 'renamed-root';
[$next, $c] = nav(['db' => $db, 'cache' => $cache]);
check('next request sees main and category keyword change', href($next,1), 'http://shop.test/renamed-root/tyre.html');
$db->seo[0][3] = 'new-product'; [$next, $c] = nav(['db' => $db, 'cache' => $cache]);
check('next request sees product rename', href($next,1), 'http://shop.test/renamed-root/new-product.html');
unset($db->seo[0]); [$next, $c] = nav(['db' => $db, 'cache' => $cache]);
check('next request sees product keyword deletion', href($next,1), 'http://shop.test/index.php?route=product/product&amp;path=10&amp;product_id=1');
$n = count($db->log); href($next,1); check('negative keyword repeat SQL', count($db->log) - $n, 0);
// prefetchProducts(): the model of products hands over the ids of a list, the links then cost no SQL
foreach ([0,1,3] as $k) {
    $fill = function ($db) use ($k) {
        for ($i = 200; $i < 220; $i++) { $db->seo[] = [0,1,'product_id=' . $i, 'p' . $i, 1000 + $i]; $db->main[$i] = $k ? [10 + ($i % $k) => 1] : []; }
        $db->paths[12] = '12'; $db->categories[12] = ['parent_id' => 0, 'status' => 1, 'store' => 1, 'language' => 1]; $db->seo[] = [0,1,'category_id=12','third',2000];
    };
    [$plain, $c] = nav(); $fill($plain->get('db')); $expected = []; for ($i = 200; $i < 220; $i++) $expected[$i] = href($plain, $i);
    [$r, $c] = nav(); $db = $r->get('db'); $fill($db);
    $c->prefetchProducts(range(200, 219));
    check("prefetch K=$k one product query", sql($r, 'product_address_batch') + sql($r, 'product_address'), 1);
    check("prefetch K=$k category queries", sql($r, 'category_address_batch') + sql($r, 'category_address'), $k ? 1 : 0);
    $n = count($db->log); $got = []; for ($i = 200; $i < 220; $i++) $got[$i] = href($r, $i);
    check("prefetch K=$k links cost no SQL", count($db->log) - $n, 0);
    check("prefetch K=$k links equal one by one", $got, $expected);
}
[$r, $c] = nav(); $db = $r->get('db');
$c->prefetchProducts(array_merge(range(5000, 6200), ['bad', 0, -1, '007', 5000, [1]]));
check('prefetch 1201 ids: batches of 500', sql($r, 'product_address_batch'), 3);
check('prefetch: an unknown id is memoized without a keyword', call($c, 'readProductAddressData', 6200), ['keyword' => null, 'main_category_id' => null]);
$n = count($db->log); $c->prefetchProducts([5000, 1]); $new = array_slice($db->log, $n);
check('prefetch: only the new id is read, as one id', count(preg_grep('~FROM \(SELECT 1 AS product_id\) p~', $new)) . '/' . count(preg_grep('~AS product_id~', $new)), '1/1');
$c->prefetchProducts('not a list'); $c->prefetchProducts([]);
check('prefetch: no list, no SQL', count($db->log) - $n, count($new));
[$r, $c] = nav(['config' => ['config_seo_url_type' => 'default']]); $c->prefetchProducts([1, 2]);
check('prefetch: standard SEO URL type reads nothing', sql($r, 'all'), 0);
[$r, $c] = nav(['config' => ['config_seo_url_include_path' => 0]]); $c->prefetchProducts([1, 2]);
check('prefetch: path off reads no category', sql($r, 'category_address') + sql($r, 'category_address_batch'), 0);
[$r, $c] = nav(); $db = $r->get('db'); $cache = $r->get('cache'); $c->prefetchProducts([1]); href($r, 1);
$db->seo[0][3] = 'prefetched-rename'; $db->main[1] = [10 => 1]; [$next, $c] = nav(['db' => $db, 'cache' => $cache]); $c->prefetchProducts([1]);
check('prefetch: the next request sees a rename and a new main category', href($next, 1), 'http://shop.test/moto/prefetched-rename.html');
[, $reg] = run('/');
check('index() hands the controller to the model of products', $reg->get('seo_pro_links') instanceof ControllerExtensionStartupSeoPro, true);
[$r, $c] = nav(); $before = href($r,1); $r->get('config')->set('config_language_id',2);
check('language context is independent', href($r,1), 'http://shop.test/index.php?route=product/product&amp;path=10_11&amp;product_id=1');
// The language-2 category aliases are absent; the hierarchy itself is available in these fixtures.
echo "notices: $notices, failed: $fail\n";
exit($fail || $notices ? 1 : 0);
