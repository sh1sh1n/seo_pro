<?php
// 1.7: the admin forms of products, categories, information pages and manufacturers after OCMOD.
// The controllers, the language files and the Twig templates are the files produced by ocmod_sim.php; Twig, Request and utf8 come from the core.
// The forms are rendered and checked as HTML: one field per language, label and id, errors, the sent values, escaping, the blocked saves.
// usage: OC_DIR=... php test_content_forms.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL);
set_error_handler(function ($no, $str) { if (preg_match('~^(Undefined|Trying to access array offset)~', $str) || ($no & (E_DEPRECATED | E_USER_DEPRECATED))) return true; throw new ErrorException($str); });
if (empty($argv[1]) || !getenv('OC_DIR')) { fwrite(STDERR, "usage: OC_DIR=<opencart-3.0.5.1/upload> php test_content_forms.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/'); $OC = rtrim(getenv('OC_DIR'), '/');
foreach (['DIR_IMAGE' => '/nonexistent/', 'HTTP_CATALOG' => 'http://shop.test/', 'HTTPS_CATALOG' => 'https://shop.test/'] as $k => $v) define($k, $v);
require $OC . '/system/helper/utf8.php';
require $OC . '/system/library/request.php';
require $OC . '/system/storage/vendor/autoload.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';

class Stop extends Exception {}
class Pagination { public $total; public $page; public $limit; public $url; function render() { return ''; } }
class Any { function __call($n, $a) { return strpos($n, 'getTotal') === 0 ? 0 : (strpos($n, 'get') === 0 ? [] : ''); } function __get($k) { return $this; } }
// A model: records the writes, answers the reads from $ret
class Stub extends Any { public $calls = []; public $ret = []; function __call($n, $a) { if (preg_match('~^(add|edit|copy|delete)~', $n)) { $this->calls[] = [$n, $a]; return 1; } return array_key_exists($n, $this->ret) ? $this->ret[$n] : parent::__call($n, $a); } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Controller { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k) ?? new Any(); } }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } function has($k) { return isset($this->d[$k]); } }
class Language { public $d = []; function get($k) { return $this->d[$k] ?? $k; } function all() { return $this->d; } }
class User { function hasPermission($a, $b) { return true; } }
class Session { public $data = ['user_token' => 't']; }
class Url { function link($r, $a = '', $s = false) { return 'http://admin.test/index.php?route=' . $r . '&amp;' . $a; } }
class Response { public $output; function setOutput($o) { $this->output = $o; } function redirect($u) { throw new Stop($u); } }
class Loader { public $registry; public $out; public $data; public $html;
	function model($r) {} function language($r) {} function controller($r) { return ''; }
	function view($route, $data) { $this->data = $data;
		$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => file_get_contents(is_file($f = $this->out . '/admin/view/template/' . $route . '.twig') ? $f : $GLOBALS['OC'] . '/admin/view/template/' . $route . '.twig')]), ['autoescape' => false, 'cache' => false]);
		// A template OCMOD has not changed is the file of the core. As event/language: the language does not replace a value the controller has set
		return $this->html = $twig->render('t', $data + $this->registry->get('language')->all()); } }
foreach (['product', 'category', 'information', 'manufacturer'] as $e) require $out . '/admin/controller/catalog/' . $e . '.php';

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
const LANGS = [['language_id' => '1', 'code' => 'en-gb', 'name' => 'English'], ['language_id' => '2', 'code' => 'ru-ru', 'name' => 'Русский']];

// One request to catalog/$e. $post null: GET. $raw: the POST is not passed through Request::clean(). $ret: answers of the entity model.
function run($e, $action, $get, $post = null, $ready = true, $ret = [], $raw = false) {
	global $out;
	$r = new Registry(); $model = new Stub(); $model->ret = $ret;
	$lang = new Language(); $_ = []; require $GLOBALS['OC'] . '/admin/language/en-gb/en-gb.php'; require $out . '/admin/language/en-gb/catalog/' . $e . '.php'; $lang->d = $_;
	$req = new Request(); $req->get = $get; $req->post = $post === null ? [] : ($raw ? $post : $req->clean($post)); $req->server = ['REQUEST_METHOD' => $post === null ? 'GET' : 'POST', 'HTTPS' => ''];
	$config = new Config(); $config->d = ['config_language_id' => 1, 'config_limit_admin' => 10, 'seo_pro_schema_failed' => false];
	foreach (Seo_pro_schema::FLAGS as $flag) $config->d[$flag] = $ready;
	$load = new Loader(); $load->registry = $r; $load->out = $out;
	$languages = new Stub(); $languages->ret = ['getLanguages' => LANGS];
	foreach (['model_catalog_' . $e => $model, 'model_localisation_language' => $languages, 'user' => new User(), 'language' => $lang, 'request' => $req, 'config' => $config, 'load' => $load, 'session' => new Session(), 'url' => new Url(), 'response' => new Response()] as $k => $v) $r->set($k, $v);
	$class = 'ControllerCatalog' . ucfirst($e); $redirect = false;
	try { (new $class($r))->$action(); } catch (Stop $x) { $redirect = true; }
	return ['calls' => $model->calls, 'redirect' => $redirect, 'data' => $load->data ?? [], 'html' => $load->html ?? '', 'post' => $req->post, 'lang' => $lang->d];
}
function attr($html, $name, $attr = 'value') { return preg_match_all('~<(?:input|textarea)[^>]*name="' . preg_quote($name, '~') . '"[^>]*>~', $html, $m) === 1 ? (preg_match('~ ' . $attr . '="([^"]*)"~', $m[0][0], $v) ? $v[1] : null) : 'count ' . count($m[0]); }
function textarea($html, $name) { return preg_match_all('~<textarea[^>]*name="' . preg_quote($name, '~') . '"[^>]*>(.*?)</textarea>~s', $html, $m) === 1 ? $m[1][0] : 'count ' . count($m[0]); }
// Ids built in JavaScript strings of the core templates are not ids of the page
function dup_ids($html) { preg_match_all('~ id="([^"\']+)"~', $html, $m); return array_values(array_unique(array_diff_assoc($m[1], array_unique($m[1])))); }
function written($x) { return array_map(fn($c) => $c[0], $x['calls']); }

const XSS = '"><img src=x onerror=alert(123)> &amp; it\'s'; const XSS_ESC = '&quot;&gt;&lt;img src=x onerror=alert(123)&gt; &amp; it\'s';
$ent = [
	'product' => ['product_id', 'getProductDescriptions', ['model' => 'M'], 'name'],
	'category' => ['category_id', 'getCategoryDescriptions', ['parent_id' => '0'], 'name'],
	'information' => ['information_id', 'getInformationDescriptions', [], 'title']];
foreach ($ent as $e => [$key, $getter, $extra, $nm]) {
	echo "-- $e form\n";
	$pd = $e . '_description';
	$stored = [1 => [$nm => 'One', 'meta_title' => 'T1', 'meta_h1' => 'H1 &quot;x&quot; &amp;', 'description' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => ''], 2 => [$nm => 'Zwei', 'meta_title' => 'T2', 'description' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '']];
	$x = run($e, 'edit', [$key => '7'], null, true, [$getter => $stored, 'get' . ucfirst($e) => [$key => '7']]);
	$h = $x['html'];
	check("$e GET: one H1 field per language with the stored value, escaped once", [attr($h, "{$pd}[1][meta_h1]"), attr($h, "{$pd}[2][meta_h1]")], ['H1 &quot;x&quot; &amp;', '']);
	check("$e GET: label for = id of the field, the label text", [attr($h, "{$pd}[1][meta_h1]", 'id'), substr_count($h, '<label class="col-sm-2 control-label" for="input-meta-h11">HTML Tag H1</label>'), substr_count($h, 'for="input-meta-h12"')], ['input-meta-h11', 1, 1]);
	check("$e GET: the field is between meta_title and meta_description, not required", (bool)preg_match('~name="' . $pd . '\[1\]\[meta_title\]".*?<div class="form-group">\s*<label class="col-sm-2 control-label" for="input-meta-h11">.*?name="' . $pd . '\[1\]\[meta_description\]"~s', $h), true);
	check("$e GET: no duplicate id in the form, no error, no warning", [dup_ids($h), substr_count($h, 'HTML Tag H1 must be'), $x['data']['error_warning']], [[], 0, '']);
	// A row written in the database by an import or another tool, not escaped: the field shows it as text, it never becomes markup
	$x = run($e, 'edit', [$key => '7'], null, true, [$getter => array_replace_recursive($stored, [1 => ['meta_h1' => XSS]]), 'get' . ucfirst($e) => [$key => '7']]);
	check("$e GET of a raw stored H1: escaped in the value, no tag in the page", [attr($x['html'], "{$pd}[1][meta_h1]"), substr_count($x['html'], '<img src=x')], [XSS_ESC, 0]);

	// The core requires a description of 3 characters for information pages
	$post = [$pd => [1 => [$nm => 'One', 'meta_title' => 'T1', 'description' => 'Text', 'meta_h1' => 'Шина "K60" & <b>'], 2 => [$nm => 'Zwei', 'meta_title' => 'T2', 'description' => 'Text', 'meta_h1' => '']]] + $extra;
	$x = run($e, 'edit', [$key => '7'], $post);
	check("$e POST valid: saved once with the H1 escaped once by Request::clean()", [written($x), $x['calls'][0][1][1][$pd][1]['meta_h1'] ?? null, $x['redirect']], [['edit' . ucfirst($e)], 'Шина &quot;K60&quot; &amp; &lt;b&gt;', true]);
	$x = run($e, 'add', [], $post);
	check("$e POST add valid: saved", written($x), ['add' . ucfirst($e)]);
	$p = $post; unset($p[$pd][1]['meta_h1']);
	$x = run($e, 'edit', [$key => '7'], $p);
	check("$e POST without meta_h1 (an importer, an old form): saved, the model keeps the stored H1", [written($x), array_key_exists('meta_h1', $x['calls'][0][1][1][$pd][1] ?? [])], [['edit' . ucfirst($e)], false]);

	$p = $post; $p[$pd][2]['meta_h1'] = str_repeat('я', 256);
	$x = run($e, 'edit', [$key => '7'], $p); $h = $x['html'];
	check("$e 256 letters: not saved, the error under language 2 only", [written($x), array_keys($x['data']['error_meta_h1']), (bool)preg_match('~name="' . $pd . '\[2\]\[meta_h1\]"[^>]*/>\s*<div class="text-danger">HTML Tag H1 must be text of at most 255 characters!</div>~', $h)], [[], [2], true]);
	check("$e 256 letters: the form keeps the sent values", [attr($h, "{$pd}[1][meta_h1]"), attr($h, "{$pd}[2][meta_h1]"), attr($h, "{$pd}[1][meta_title]")], ['Шина &quot;K60&quot; &amp; &lt;b&gt;', str_repeat('я', 256), 'T1']);
	check("$e 256 letters: the general warning", $x['data']['error_warning'], $x['lang']['error_warning']);
	$p = $post; $p[$pd][1]['meta_h1'] = str_repeat('a', 255);
	check("$e 255 characters: saved", written(run($e, 'edit', [$key => '7'], $p)), ['edit' . ucfirst($e)]);
	$p = $post; $p[$pd][1]['meta_h1'] = ['x', 'y'];
	$x = run($e, 'edit', [$key => '7'], $p, true, [], true);
	check("$e meta_h1 as an array: not saved, the field is empty, never \"Array\"", [written($x), attr($x['html'], "{$pd}[1][meta_h1]"), strpos($x['html'], 'Array') === false, isset($x['data']['error_meta_h1'][1])], [[], '', true, true]);

	foreach (['the flag is false' => false, 'the flag is missing' => null] as $name => $flag) {
		$x = run($e, 'edit', [$key => '7'], $post, $flag);
		check("$e not ready, $name: POST not saved, the schema warning", [written($x), $x['data']['error_warning']], [[], $x['lang']['error_seo_pro_h1_schema']]);
		$x = run($e, 'edit', [$key => '7'], null, $flag, [$getter => $stored]);
		check("$e not ready, $name: GET shows the warning", strpos($x['html'], $x['lang']['error_seo_pro_h1_schema']) !== false, true);
	}
	echo "\n";
}

echo "-- product copy\n";
$x = run('product', 'copy', [], ['selected' => ['7']], false);
check('copy while the column is not ready: no copy, the warning in the list', [written($x), $x['data']['error_warning'] ?? null], [[], $x['lang']['error_seo_pro_h1_schema']]);
$x = run('product', 'copy', [], ['selected' => ['7']]);
check('copy when ready', written($x), ['copyProduct']);

echo "\n-- manufacturer form\n";
$fields = ['description', 'meta_title', 'meta_h1', 'meta_description', 'meta_keyword'];
$stored = [1 => ['description' => '&lt;p&gt;Reifen&lt;/p&gt;', 'meta_title' => 'MT &quot;1&quot;', 'meta_h1' => 'MH1', 'meta_description' => 'MD', 'meta_keyword' => 'MK'], 2 => ['description' => '', 'meta_title' => 'МТ', 'meta_h1' => '', 'meta_description' => '', 'meta_keyword' => '']];
$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], null, true, ['getManufacturer' => ['name' => 'Heidenau', 'image' => '', 'sort_order' => '0'], 'getManufacturerDescriptions' => $stored]); $h = $x['html'];
check('GET: one tab and one pane, the General and SEO tabs stay', [substr_count($h, 'href="#tab-seo-pro-description"'), substr_count($h, 'id="tab-seo-pro-description"'), substr_count($h, 'id="tab-general"'), substr_count($h, 'id="tab-seo"')], [1, 1, 1, 1]);
check('GET: the tab follows General, the pane comes before SEO', (bool)preg_match('~href="#tab-general".*href="#tab-seo-pro-description".*href="#tab-seo".*id="tab-general".*id="tab-seo-pro-description".*id="tab-seo"~s', $h), true);
check('GET: the stored values of language 1', [textarea($h, 'manufacturer_description[1][description]'), attr($h, 'manufacturer_description[1][meta_title]'), attr($h, 'manufacturer_description[1][meta_h1]'), textarea($h, 'manufacturer_description[1][meta_description]'), textarea($h, 'manufacturer_description[1][meta_keyword]')], ['&lt;p&gt;Reifen&lt;/p&gt;', 'MT &quot;1&quot;', 'MH1', 'MD', 'MK']);
check('GET: language 2', [attr($h, 'manufacturer_description[2][meta_title]'), attr($h, 'manufacturer_description[2][meta_h1]')], ['МТ', '']);
$raw = [1 => array_fill_keys($fields, XSS)]; $raw[1]['description'] = '</textarea><img src=x onerror=alert(1)><textarea>';
$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], null, true, ['getManufacturer' => ['name' => 'Heidenau', 'image' => '', 'sort_order' => '0'], 'getManufacturerDescriptions' => $raw]); $hr = $x['html'];
check('GET of raw stored values (an import): every input and textarea shows them as text, no tag in the page', [attr($hr, 'manufacturer_description[1][meta_title]'), attr($hr, 'manufacturer_description[1][meta_h1]'), textarea($hr, 'manufacturer_description[1][meta_description]'), textarea($hr, 'manufacturer_description[1][meta_keyword]'), textarea($hr, 'manufacturer_description[1][description]'), substr_count($hr, '<img src=x')],
	[XSS_ESC, XSS_ESC, XSS_ESC, XSS_ESC, '&lt;/textarea&gt;&lt;img src=x onerror=alert(1)&gt;&lt;textarea&gt;', 0]);
$ids = []; foreach ([1, 2] as $l) foreach (['description' => 'input-description', 'meta_title' => 'input-meta-title', 'meta_h1' => 'input-meta-h1', 'meta_description' => 'input-meta-description', 'meta_keyword' => 'input-meta-keyword'] as $f => $id) $ids[] = attr($h, "manufacturer_description[$l][$f]", 'id') === "$id$l" && substr_count($h, "for=\"$id$l\"") === 1;
check('GET: every field has its id with the language and one label for it', array_unique($ids), [true]);
check('GET: no duplicate id, no required field added, the editor of the core once', [dup_ids($h), substr_count($h, 'form-group required'), substr_count($h, 'summernote/summernote.min.js'), substr_count($h, 'data-toggle="summernote"')], [[], 1, 1, 2]);
$x = run('manufacturer', 'add', [], null);
check('GET add: empty fields, no warning', [attr($x['html'], 'manufacturer_description[1][meta_h1]'), textarea($x['html'], 'manufacturer_description[2][description]'), $x['data']['error_warning']], ['', '', '']);

$post = ['name' => 'Heidenau', 'sort_order' => '0', 'manufacturer_description' => [1 => array_fill_keys($fields, 'x'), 2 => ['meta_h1' => 'Хайденау & Co']]];
$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], $post);
check('POST valid: saved with the descriptions escaped once', [written($x), $x['calls'][0][1][1]['manufacturer_description'][2]['meta_h1'] ?? null], [['editManufacturer'], 'Хайденау &amp; Co']);
$p = $post; $p['manufacturer_description'][2]['meta_title'] = str_repeat('я', 256);
$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], $p, true, ['getManufacturerDescriptions' => $stored]); $h = $x['html'];
check('meta_title of 256 letters: not saved, the error of this field and language, the warning', [written($x), $x['data']['error_seo_pro_description'], $x['data']['error_warning']], [[], [2 => ['meta_title' => 'Must be text of at most 255 characters!']], 'Warning: Please check the form carefully for errors!']);
check('the error is shown under the field', (bool)preg_match('~name="manufacturer_description\[2\]\[meta_title\]"[^>]*/>\s*<div class="text-danger">Must be text of at most 255 characters!</div>~', $h), true);
check('the sent values are kept, the fields not sent show the stored ones', [attr($h, 'manufacturer_description[2][meta_h1]'), attr($h, 'manufacturer_description[1][meta_h1]'), attr($h, 'manufacturer_description[2][meta_title]'), textarea($h, 'manufacturer_description[2][meta_keyword]')], ['Хайденау &amp; Co', 'x', str_repeat('я', 256), '']);
$p = $post; $p['manufacturer_description'][1]['description'] = str_repeat('я', 32768);
$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], $p);
check('description over 65535 bytes: not saved, its own error', [written($x), $x['data']['error_seo_pro_description'][1] ?? null], [[], ['description' => 'The description must be text of at most 65535 bytes!']]);
foreach (['a string' => 'x', 'a language that is a string' => [1 => 'x'], 'a field that is an array' => [1 => ['meta_h1' => ['a']]]] as $name => $value) {
	$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], ['name' => 'Heidenau', 'manufacturer_description' => $value], true, [], true);
	check("manufacturer_description is $name: not saved, nothing printed as text", [written($x), strpos($x['html'], 'Array') === false, attr($x['html'], 'manufacturer_description[1][meta_h1]')], [[], true, '']);
}
$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], ['name' => 'Heidenau', 'manufacturer_description' => 'x'], true, [], true);
check('a wrong container: the warning names it', $x['data']['error_warning'], 'Warning: the descriptions were sent in a wrong form, nothing is saved!');

foreach (['the flag is false' => false, 'the flag is missing' => null] as $name => $flag) {
	$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], $post, $flag);
	check("not ready, $name: POST not saved, the schema warning", [written($x), $x['data']['error_warning']], [[], $x['lang']['error_seo_pro_md_schema']]);
	$x = run('manufacturer', 'add', [], ['name' => 'New'], $flag);
	check("not ready, $name: add without descriptions is not saved either", written($x), []);
	$x = run('manufacturer', 'delete', [], ['selected' => ['5']], $flag);
	check("not ready, $name: delete is blocked", [written($x), $x['data']['error_warning'] ?? null], [[], $x['lang']['error_seo_pro_md_schema']]);
	$x = run('manufacturer', 'edit', ['manufacturer_id' => '5'], null, $flag, ['getManufacturer' => ['name' => 'Heidenau']]);
	check("not ready, $name: GET shows the warning on top and in the tab", substr_count($x['html'], $x['lang']['error_seo_pro_md_schema']), 2);
}
$x = run('manufacturer', 'delete', [], ['selected' => ['5']]);
check('delete when ready', written($x), ['deleteManufacturer']);

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);
