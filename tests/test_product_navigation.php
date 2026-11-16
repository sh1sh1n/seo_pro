<?php
require __DIR__ . '/test_seo_pro.php';
function nav($opts = []) { [$r, $c] = boot($opts); $r->get('url')->addRewrite($c); return [$r, $c]; }
function href($r, $id, $extras = []) { return $r->get('url')->link('product/product', ['product_id' => $id] + $extras); }
[$r, $c] = nav(); $before = $r->get('request')->get;
check('general product href', href($r, 1, ['manufacturer_id' => 5, 'sort' => 'p.price', 'order' => 'ASC', 'page' => 2, 'limit' => 20, 'utm_source' => 'a&b', 'custom' => ['x' => 'a+b']]), 'http://shop.test/moto/enduro/tyre.html?utm_source=a%26b&amp;custom%5Bx%5D=a%2Bb');
check('generation leaves Request intact', $r->get('request')->get, $before);
check('generation never calls model', $r->get('model_catalog_product')->calls, 0);
check('no map in product generation', sql($r, 'map'), 0);
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
    $count = count($db->log); for ($i = 200; $i < 220; $i++) href($r, $i);
    check("20 products K=$k repeat SQL", count($db->log) - $count, 0);
}
echo "notices: $notices, failed: $fail\n";
exit($fail || $notices ? 1 : 0);
