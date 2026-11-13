<?php
// 1.7: the storefront after OCMOD. The catalog models of products and manufacturers on a database stub,
// the controllers of products, categories, information pages and manufacturers with stub models:
// the heading, the meta tags and the description passed to the template.
// usage: php test_storefront.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
// The core controllers read many values the stubs do not have
set_error_handler(function ($no, $str) { if (preg_match('~^(Undefined|Trying to access array offset|A non-numeric value)~', $str) || ($no & (E_DEPRECATED | E_USER_DEPRECATED))) return true; throw new ErrorException($str); });
if (empty($argv[1])) { fwrite(STDERR, "usage: php test_storefront.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/');
foreach (['DIR_IMAGE' => '/nonexistent/', 'DIR_TEMPLATE' => '/nonexistent/'] as $k => $v) define($k, $v);

class Res { public $rows; public $row; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
class DB { public $log = []; public $answer = [];
	function escape($s) { return addslashes((string)$s); }
	function query($sql) { $this->log[] = $sql; return new Res($this->answer); } }
// Anything the test does not care about: lists are empty, everything else is an empty string
class Any implements ArrayAccess { function __call($n, $a) { return strpos($n, 'getTotal') === 0 ? 0 : (strpos($n, 'get') === 0 ? [] : ''); } function __get($k) { return $this; }
	function offsetExists($o): bool { return false; } function offsetGet($o): mixed { return ''; } function offsetSet($o, $v): void {} function offsetUnset($o): void {} }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Base { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k) ?? new Any(); } }
abstract class Model extends Base {}
abstract class Controller extends Base {}
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
class Document { public $title; public $description = ''; public $keywords = ''; function setTitle($t) { $this->title = $t; } function setDescription($d) { $this->description = $d; } function setKeywords($k) { $this->keywords = $k; } function __call($n, $a) { return []; } }
class Request { public $get = []; public $post = []; public $server = ['REQUEST_METHOD' => 'GET']; public $cookie = []; }
class Pagination { public $total; public $page; public $limit; public $url; function render() { return ""; } }
class Language { function get($k) { return $k; } function all() { return []; } }
class Loader { public $data; function model($r) {} function language($r) {} function controller($r) { return ''; } function view($r, $d) { $this->data = $d; return ''; } }
class Info extends Any { public $info; public $method; function __construct($method, $info) { $this->method = $method; $this->info = $info; } function __call($n, $a) { return $n === $this->method ? $this->info : parent::__call($n, $a); } }
require dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php'; // the autoloader of the shop

require $out . '/catalog/model/catalog/product.php';
require $out . '/catalog/model/catalog/manufacturer.php';
foreach (['product/product', 'product/category', 'information/information', 'product/manufacturer'] as $c) require $out . '/catalog/controller/' . $c . '.php';

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
function reg($flags = []) { $r = new Registry(); $c = new Config(); $c->d = $flags + ['config_language_id' => 2, 'config_store_id' => 1, 'config_customer_group_id' => 1, 'config_theme' => 'default', 'theme_default_product_limit' => 15, 'theme_default_product_description_length' => 100];
	foreach (['db' => new DB(), 'config' => $c, 'document' => new Document(), 'request' => new Request(), 'load' => new Loader(), 'language' => new Language()] as $k => $v) $r->set($k, $v); return $r; }

echo "-- catalog model of products\n";
$row = ['product_id' => '7', 'name' => 'Name', 'meta_title' => 'Title', 'meta_h1' => 'H1 &amp; more'];
$r = reg(); $r->get('db')->answer = [$row];
check('getProduct passes meta_h1', (new ModelCatalogProduct($r))->getProduct(7)['meta_h1'] ?? 'missing', 'H1 &amp; more');
$r = reg(); unset($row['meta_h1']); $r->get('db')->answer = [$row];
check('getProduct without the column: meta_h1 is ""', (new ModelCatalogProduct($r))->getProduct(7)['meta_h1'] ?? 'missing', '');
check('getProduct: the query is the core one', count(preg_grep('~meta_h1|manufacturer_description~', $r->get('db')->log)), 0);

echo "\n-- catalog model of manufacturers\n";
$core = "SELECT * FROM oc_manufacturer m LEFT JOIN oc_manufacturer_to_store m2s ON (m.manufacturer_id = m2s.manufacturer_id) WHERE m.manufacturer_id = '5' AND m2s.store_id = '1'";
foreach (['the flag is false' => false, 'the flag is missing' => null] as $name => $flag) {
	$r = reg(['seo_pro_manufacturer_description_ready' => $flag]); $r->get('db')->answer = [['manufacturer_id' => '5', 'name' => 'Heidenau']];
	$info = (new ModelCatalogManufacturer($r))->getManufacturer(5);
	check("getManufacturer, $name: the core query, no JOIN", [$r->get('db')->log, $info], [[$core], ['manufacturer_id' => '5', 'name' => 'Heidenau']]);
}
$r = reg(['seo_pro_manufacturer_description_ready' => true]); $r->get('db')->answer = [['manufacturer_id' => '5', 'name' => 'Heidenau', 'store_id' => '1', 'meta_h1' => null]];
$info = (new ModelCatalogManufacturer($r))->getManufacturer(5); $sql = $r->get('db')->log[0];
check('getManufacturer, ready: one query', count($r->get('db')->log), 1);
check('the language is a condition of the LEFT JOIN, not of WHERE: a manufacturer without a translation is found', [strpos($sql, "LEFT JOIN oc_manufacturer_description md ON (m.manufacturer_id = md.manufacturer_id AND md.language_id = '2')") !== false, strpos(substr($sql, strpos($sql, 'WHERE')), 'md.') === false], [true, true]);
check('the store check of the core stays', strpos($sql, "WHERE m.manufacturer_id = '5' AND m2s.store_id = '1'") !== false, true);
check('m.* and the text fields of md, never md.* (its NULL manufacturer_id would hide the id)', [strpos($sql, 'SELECT m.*, m2s.store_id, md.description, md.meta_title, md.meta_h1, md.meta_description, md.meta_keyword FROM') === 0, strpos($sql, 'md.*') === false, strpos($sql, 'SELECT *') === false], [true, true, true]);
check('getManufacturer returns the row', $info['manufacturer_id'], '5');

// One controller call; $method of the stub model returns $info. Returns [data, document].
function page($route, $method, $info, $flags, $get) {
	[$file, $action] = [explode('/', $route), $route === 'product/manufacturer' ? 'info' : 'index'];
	$class = 'Controller' . str_replace('_', '', ucwords($file[0], '_')) . ucfirst($file[1]);
	$r = reg($flags); $r->get('request')->get = $get;
	$model = new Info($method, $info);
	foreach (['product', 'category', 'information', 'manufacturer'] as $m) $r->set('model_catalog_' . $m, $model);
	(new $class($r))->$action();
	return [$r->get('load')->data ?? [], $r->get('document')];
}
$pages = [
	'product' => ['product/product', 'getProduct', ['product_id' => '7', 'name' => 'Tyre K60', 'meta_title' => 'Title', 'meta_description' => 'D', 'meta_keyword' => 'K', 'description' => '', 'image' => '', 'quantity' => 1, 'minimum' => 1, 'price' => 1, 'tax_class_id' => 0, 'special' => null, 'rating' => 0, 'reviews' => 0, 'model' => 'M', 'reward' => 0, 'points' => 0, 'manufacturer_id' => 0, 'manufacturer' => '', 'stock_status' => '', 'date_available' => '2020-01-01', 'tag' => ''], 'name', ['product_id' => '7']],
	'category' => ['product/category', 'getCategory', ['category_id' => '3', 'name' => 'Tyres', 'meta_title' => 'Title', 'meta_description' => 'D', 'meta_keyword' => 'K', 'description' => '', 'image' => ''], 'name', ['path' => '3']],
	'information' => ['information/information', 'getInformation', ['information_id' => '4', 'title' => 'About', 'meta_title' => 'Title', 'meta_description' => 'D', 'meta_keyword' => 'K', 'description' => ''], 'title', ['information_id' => '4']]];
foreach ($pages as $e => [$route, $method, $info, $name, $get]) {
	echo "\n-- $route\n";
	$flag = ['seo_pro_h1_' . $e . '_ready' => true];
	[$d, $doc] = page($route, $method, $info + ['meta_h1' => 'Шина &quot;K60&quot;'], $flag, $get);
	check("$e: a filled H1 is the heading, as stored (escaped once)", $d['heading_title'] ?? null, 'Шина &quot;K60&quot;');
	check("$e: the title and meta of the core stay", [$doc->title, $doc->description, $doc->keywords], ['Title', 'D', 'K']);
	foreach (['"0" is a filled H1' => ['0', '0'], '"" keeps the name' => ['', $info[$name]], 'no key (old cache, no column) keeps the name' => [null, $info[$name]], 'null keeps the name' => [false, $info[$name]], 'an array keeps the name' => [['x'], $info[$name]]] as $case => [$h1, $exp]) {
		$i = $info; if ($h1 !== null) $i['meta_h1'] = $h1 === false ? null : $h1;
		[$d] = page($route, $method, $i, $flag, $get);
		check("$e: $case", $d['heading_title'] ?? null, $exp);
	}
	[$d] = page($route, $method, $info + ['meta_h1' => '"><img src=x onerror=alert(1)> &amp;'], $flag, $get);
	check("$e: a raw H1 written by another tool is escaped, the entities kept", $d['heading_title'] ?? null, '&quot;&gt;&lt;img src=x onerror=alert(1)&gt; &amp;');
	[$d] = page($route, $method, $info + ['meta_h1' => 'H1'], [], $get);
	check("$e: the column is not ready: the name stays", $d['heading_title'] ?? null, $info[$name]);
}

echo "\n-- product/manufacturer/info\n";
$m = ['manufacturer_id' => '5', 'name' => 'Heidenau', 'image' => ''];
$ready = ['seo_pro_manufacturer_description_ready' => true];
[$d, $doc] = page('product/manufacturer', 'getManufacturer', $m + ['description' => '&lt;p&gt;Reifen &amp;amp; Co&lt;/p&gt;', 'meta_title' => 'MT', 'meta_h1' => 'MH1', 'meta_description' => 'MD', 'meta_keyword' => 'MK'], $ready, ['manufacturer_id' => '5']);
check('ready: title, description, keywords, heading', [$doc->title, $doc->description, $doc->keywords, $d['heading_title'] ?? null], ['MT', 'MD', 'MK', 'MH1']);
check('ready: the description is decoded once, as the core does with descriptions', $d['description'] ?? null, '<p>Reifen &amp; Co</p>');
check('the breadcrumb keeps the name', end($d['breadcrumbs'])['text'], 'Heidenau');
[$d, $doc] = page('product/manufacturer', 'getManufacturer', $m + ['description' => null, 'meta_title' => null, 'meta_h1' => null, 'meta_description' => null, 'meta_keyword' => null], $ready, ['manufacturer_id' => '5']);
check('no translation (LEFT JOIN gives NULL): the name, empty meta and description', [$doc->title, $doc->description, $doc->keywords, $d['heading_title'] ?? null, $d['description'] ?? null], ['Heidenau', '', '', 'Heidenau', '']);
[$d, $doc] = page('product/manufacturer', 'getManufacturer', $m + ['description' => '', 'meta_title' => '0', 'meta_h1' => '0', 'meta_description' => '', 'meta_keyword' => ''], $ready, ['manufacturer_id' => '5']);
check('"0" is a filled title and H1, "" keeps the core values', [$doc->title, $doc->description, $d['heading_title'] ?? null], ['0', '', '0']);
[$d, $doc] = page('product/manufacturer', 'getManufacturer', $m + ['meta_title' => 'MT', 'meta_h1' => 'MH1', 'description' => 'x'], [], ['manufacturer_id' => '5']);
check('the table is not ready: as the core, the description is empty', [$doc->title, $d['heading_title'] ?? null, $d['description'] ?? null], ['Heidenau', 'Heidenau', '']);
$x = '"><script>x</script>';
[$d, $doc] = page('product/manufacturer', 'getManufacturer', $m + ['description' => '<p>raw</p>', 'meta_title' => $x, 'meta_h1' => $x, 'meta_description' => $x, 'meta_keyword' => $x], $ready, ['manufacturer_id' => '5']);
check('raw values written by another tool: title, meta and heading escaped; the description stays HTML', [$doc->title, $doc->description, $doc->keywords, $d['heading_title'] ?? null, $d['description'] ?? null], array_merge(array_fill(0, 4, '&quot;&gt;&lt;script&gt;x&lt;/script&gt;'), ['<p>raw</p>']));
[$d, $doc] = page('product/manufacturer', 'getManufacturer', [], $ready, ['manufacturer_id' => '99']);
check('an unknown manufacturer: the core not-found page', $d['heading_title'] ?? null, 'text_error');

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);
