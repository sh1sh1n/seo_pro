<?php
// F3: the SEO settings in setting/setting and setting/store after OCMOD: loading, validation, saving, the rendered form.
// The controllers, the language files and the Twig templates are the files produced by ocmod_sim.php; Twig and Request come from the core.
// usage: OC_DIR=... php test_store_settings.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL);
// The core controllers read fields the stubs do not send
set_error_handler(function ($no, $str) { if (preg_match('~^(Undefined|Trying to access array offset)~', $str) || ($no & (E_DEPRECATED | E_USER_DEPRECATED))) return true; throw new ErrorException($str); });
if (empty($argv[1]) || !getenv('OC_DIR')) { fwrite(STDERR, "usage: OC_DIR=<opencart-3.0.5.1/upload> php test_store_settings.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/'); $OC = rtrim(getenv('OC_DIR'), '/');
foreach (['DIR_IMAGE' => '/nonexistent/', 'HTTP_CATALOG' => 'http://shop.test/', 'HTTPS_CATALOG' => 'https://shop.test/'] as $k => $v) define($k, $v);
require $OC . '/system/helper/utf8.php';
require $OC . '/system/library/request.php';
require $OC . '/system/storage/vendor/autoload.php';

class Stop extends Exception {}
// Models the test does not care about: lists are empty, everything else (an image, a title) is an empty string
class Any { function __call($n, $a) { return strpos($n, 'get') === 0 ? [] : ''; } function __get($k) { return $this; } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Controller { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k) ?? new Any(); } }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } function has($k) { return isset($this->d[$k]); } }
class Language { public $d = []; function get($k) { return $this->d[$k] ?? $k; } function all() { return $this->d; } }
class User { public $allowed = true; function hasPermission($a, $b) { return $this->allowed; } }
class Session { public $data = ['user_token' => 't']; }
class Url { function link($r, $a = '', $s = false) { return 'http://admin.test/index.php?route=' . $r . '&amp;' . $a; } }
class Response { public $output; function setOutput($o) { $this->output = $o; } function redirect($u) { throw new Stop($u); } }
// The settings table: store_id => [key => value]
class Settings { public $rows = []; public $saved = []; public $reads = [];
	function getSetting($code, $store_id = 0) { $this->reads[] = (int)$store_id; return $this->rows[(int)$store_id] ?? []; }
	function editSetting($code, $data, $store_id = 0) { $this->saved[] = [(int)$store_id, $data]; $this->rows[(int)$store_id] = array_filter($data, fn($k) => strpos($k, 'config') === 0, ARRAY_FILTER_USE_KEY); } }
class Stores { public $added = 0; public $edited = []; function addStore($d) { $this->added++; return 5; } function editStore($id, $d) { $this->edited[] = $id; } }
class Loader { public $registry; public $out; public $data; public $html;
	function model($r) {} function language($r) {} function controller($r) { return ''; }
	function view($route, $data) { $this->data = $data;
		$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => file_get_contents($this->out . '/admin/view/template/' . $route . '.twig')]), ['autoescape' => false, 'cache' => false]);
		return $this->html = $twig->render('t', $this->registry->get('language')->all() + $data); } }
require $out . '/admin/controller/setting/store.php';
require $out . '/admin/controller/setting/setting.php';

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
const SEO = ['config_seo_url', 'config_seo_url_type', 'config_seo_url_include_path', 'config_seo_url_postfix'];
function seo($a) { $r = []; foreach (SEO as $k) if (array_key_exists($k, $a)) $r[$k] = $a[$k]; return $r; }
function vals($url, $type, $path, $postfix) { return array_combine(SEO, [$url, $type, $path, $postfix]); }
// The fields the core validation of each form requires
$store_post = ['config_url' => 'http://two.test/', 'config_meta_title' => 'Two', 'config_name' => 'Two', 'config_owner' => 'Owner', 'config_address' => 'Address 12345', 'config_email' => 'a@two.test', 'config_telephone' => '12345', 'config_customer_group_id' => '1'];
$main_post = ['config_meta_title' => 'One', 'config_name' => 'One', 'config_owner' => 'Owner', 'config_address' => 'Address 12345', 'config_email' => 'a@one.test', 'config_telephone' => '12345', 'config_customer_group_id' => '1',
	'config_limit_admin' => '20', 'config_login_attempts' => '5', 'config_voucher_min' => '1', 'config_voucher_max' => '1000', 'config_processing_status' => ['2'], 'config_complete_status' => ['5'], 'config_error_filename' => 'error.log', 'config_encryption' => str_repeat('k', 64)];

// One request. $form: 'store' (with $store_id, or null for a new store) or 'main'. $post null: GET. $raw: the POST is not passed through Request::clean().
function run($form, $store_id, $rows, $post = null, $opts = []) {
	global $out;
	$r = new Registry(); $settings = new Settings(); $settings->rows = $rows; $stores = new Stores(); $user = new User(); $user->allowed = $opts['allowed'] ?? true;
	$lang = new Language(); $_ = []; require $out . '/admin/language/en-gb/setting/' . ($form === 'main' ? 'setting' : 'store') . '.php'; $lang->d = $_ + ['text_yes' => 'Yes', 'text_no' => 'No'];
	$req = new Request(); $req->get = $store_id === null ? [] : ['store_id' => (string)$store_id]; $req->post = $post === null ? [] : (empty($opts['raw']) ? $req->clean($post) : $post);
	$req->server = ['REQUEST_METHOD' => $post === null ? 'GET' : 'POST', 'HTTPS' => ''];
	$config = new Config(); $config->d = (is_array($rows[0] ?? null) ? $rows[0] : []) + ['config_timezone' => 'UTC'];
	$load = new Loader(); $load->registry = $r; $load->out = $out;
	foreach (['model_setting_setting' => $settings, 'model_setting_store' => $stores, 'user' => $user, 'language' => $lang, 'request' => $req, 'config' => $config, 'load' => $load, 'session' => new Session(), 'url' => new Url(), 'response' => new Response()] as $k => $v) $r->set($k, $v);
	$redirect = false;
	try { if ($form === 'main') (new ControllerSettingSetting($r))->index(); elseif ($store_id === null) (new ControllerSettingStore($r))->add(); else (new ControllerSettingStore($r))->edit(); } catch (Stop $e) { $redirect = true; }
	return ['saved' => $settings->saved, 'redirect' => $redirect, 'data' => $load->data ?? [], 'html' => $load->html ?? '', 'rows' => $settings->rows, 'added' => $stores->added];
}
// What the rendered form shows for the four settings: the checked radio or the selected option, 'none' when nothing is chosen
function shown($html) {
	$res = [];
	foreach (['config_seo_url', 'config_seo_url_include_path'] as $name) { $res[$name] = preg_match('~<input type="radio" name="' . $name . '" value="([01])"\s*checked="checked"~', $html, $m) ? $m[1] : 'none'; }
	preg_match('~<select id="config_seo_url_type".*?</select>~s', $html, $sel); $res['config_seo_url_type'] = preg_match('~<option value="([^"]*)" selected="selected"~', $sel[0] ?? '', $m) ? ($m[1] === '' ? 'none' : $m[1]) : 'first';
	$res['config_seo_url_postfix'] = preg_match('~name="config_seo_url_postfix" value="([^"]*)"~', $html, $m) ? $m[1] : 'missing';
	return array_replace(array_flip(SEO), $res);
}
function errors($x) { $e = []; foreach (SEO as $k) if (!empty($x['data']['error_' . substr($k, 7)])) $e[] = $k; return $e; }

$zero = vals('1', 'seo_pro', '1', '.html');            // the default store
$own = vals('0', 'seo_url', '0', '');                    // store 1: every value differs and is "empty" for PHP
$base = [0 => $zero + ['config_name' => 'One']];

echo "-- store form: GET\n";
$x = run('store', null, $base);
check('new store shows the settings of the default store', [shown($x['html']), errors($x), $x['saved']], [$zero, [], []]);
$x = run('store', 1, $base + [1 => $own + ['config_name' => 'Two']]);
check('store with own settings shows them: "0" and "" are values', [shown($x['html']), errors($x)], [$own, []]);
$x = run('store', 1, $base + [1 => ['config_name' => 'Two']]);
check('old store without records shows the default store', shown($x['html']), $zero);
$x = run('store', 1, [0 => ['config_name' => 'One'], 1 => ['config_name' => 'Two']]);
check('nothing stored anywhere: the default values', shown($x['html']), vals('0', 'seo_url', '0', ''));
$x = run('store', 1, $base + [1 => ['config_seo_url_postfix' => '']]);
check('each setting has its own source', shown($x['html']), vals('1', 'seo_pro', '1', ''));
check('the four fields are on the tab Server with the note about own settings', [(bool)preg_match('~id="tab-server">.*name="config_seo_url".*name="config_seo_url_type".*name="config_seo_url_include_path".*name="config_seo_url_postfix".*name="config_secure"~s', $x['html']), strpos($x['html'], 'saved as the own settings of this store') !== false, substr_count($x['html'], 'name="config_seo_url_postfix"')], [true, true, 1]);

echo "\n-- store form: saving\n";
$rows = $base + [1 => $own + ['config_name' => 'Two']];
$x = run('store', 1, $rows, $store_post + vals('1', 'seo_pro', '0', '.htm'));
check('valid POST: one save, to store 1', [count($x['saved']), $x['saved'][0][0] ?? null, $x['redirect']], [1, 1, true]);
check('valid POST: the sent values are saved', seo($x['saved'][0][1] ?? []), vals('1', 'seo_pro', '0', '.htm'));
check('valid POST: the other fields are not touched', array_diff_key($x['saved'][0][1], array_flip(SEO)), $store_post);
check('valid POST: the default store is not changed', seo($x['rows'][0]), $zero);
$x = run('store', 1, $rows, $store_post);
check('POST without the SEO fields keeps the own "0" and "", not the default store', [seo($x['saved'][0][1] ?? []), $x['saved'][0][0] ?? null, seo($x['rows'][1])], [$own, 1, $own]);
$x = run('store', 1, $rows, $store_post + ['config_seo_url_postfix' => '.php']);
check('POST with one SEO field: the others come from the store', seo($x['saved'][0][1] ?? []), vals('0', 'seo_url', '0', '.php'));
$x = run('store', 1, $rows, $store_post + ['config_seo_url_postfix' => '']);
check('empty postfix is a value', seo($x['saved'][0][1] ?? [])['config_seo_url_postfix'] ?? null, '');
$x = run('store', 1, $base + [1 => ['config_name' => 'Two']], $store_post);
check('old store without records: the inherited values become its own', [seo($x['saved'][0][1] ?? []), $x['saved'][0][0] ?? null], [$zero, 1]);
$x = run('store', 1, [0 => [], 1 => []], $store_post);
check('nothing stored anywhere: the default values are saved', seo($x['saved'][0][1] ?? []), vals('0', 'seo_url', '0', ''));
$x = run('store', 1, $rows, $store_post + ['config_seo_url' => 1, 'config_seo_url_include_path' => 0], ['raw' => true]);
check('int flags are saved as strings', seo($x['saved'][0][1] ?? []), vals('1', 'seo_url', '0', ''));
$x = run('store', null, $base, $store_post);
check('new store: created, the settings of the default store saved under the new id', [$x['added'], $x['saved'][0][0] ?? null, seo($x['saved'][0][1] ?? [])], [1, 5, $zero]);
$x = run('store', null, [0 => []], $store_post);
check('new store, nothing in the default store: the default values', seo($x['saved'][0][1] ?? []), vals('0', 'seo_url', '0', ''));
$x = run('store', null, $base, $store_post + vals('0', 'seo_url', '1', '/'));
check('new store with its own values', seo($x['saved'][0][1] ?? []), vals('0', 'seo_url', '1', '/'));
$a = run('store', 1, $rows, $store_post + vals('1', 'seo_pro', '1', ''));
$b = run('store', 2, $a['rows'], $store_post + vals('1', 'seo_url', '0', '.html'));
check('each store keeps its own combination', [seo($b['rows'][0]), seo($b['rows'][1]), seo($b['rows'][2])], [$zero, vals('1', 'seo_pro', '1', ''), vals('1', 'seo_url', '0', '.html')]);

echo "\n-- store form: invalid values\n";
$flag = ['true', 'false', '01', '2', '-1', '', '1.0', 'yes', ['1']];
$bad = ['config_seo_url' => $flag, 'config_seo_url_include_path' => $flag, 'config_seo_url_type' => ['', 'seo', 'SEO_PRO', '1', 'seo_url/x', ['seo_pro']], 'config_seo_url_postfix' => [['.html'], ['a' => ['b']]]];
// Request::clean() trims the values, so a value with spaces can only come from code
$raw = ['config_seo_url' => [null, true, false, 1.0, 2, ' 1'], 'config_seo_url_include_path' => [null, true, 0.0], 'config_seo_url_type' => [null, true, 1, 'seo_pro '], 'config_seo_url_postfix' => [null, 1, false]];
foreach (['clean' => $bad, 'raw' => $raw] as $mode => $set) foreach ($set as $key => $values) foreach ($values as $value) {
	$good = array_diff_key(vals('1', 'seo_pro', '1', '.htm'), [$key => 1]);
	$x = run('store', 1, $rows, $store_post + $good + [$key => $value], ['raw' => $mode === 'raw']);
	$name = "$key = " . json_encode($value);
	check("$name: nothing saved, error at the field and the warning", [$x['saved'], $x['redirect'], errors($x), $x['data']['error_warning'] ?? null], [[], false, [$key], 'Warning: Please check the form carefully for errors!']);
	$exp = array_replace(vals('1', 'seo_pro', '1', '.htm'), [$key => $key === 'config_seo_url_postfix' ? '' : 'none']);
	check("$name: no other value is chosen, the valid fields stay", shown($x['html']), $exp);
	if ($key !== 'config_seo_url_postfix' && $key !== 'config_seo_url_type') check("$name: an unchanged resubmit is rejected again", (bool)preg_match('~<input type="hidden" name="' . $key . '" value="" />~', $x['html']), true);
	check("$name: the array is not printed", strpos($x['html'], 'Array') === false, true);
}
$x = run('store', 1, $rows, $store_post + vals('2', 'x', 'no', ['a']));
check('four invalid fields: four errors with their texts', [errors($x), $x['saved'], substr_count($x['html'], 'class="text-danger">Use SEO URLs: choose Yes or No!'), substr_count($x['html'], 'SEO type: choose one of the listed types!'), substr_count($x['html'], 'SEO with categories: choose Yes or No!'), substr_count($x['html'], 'SEO postfix must be a text value!'), substr_count($x['html'], '--- Invalid value ---')], [SEO, [], 1, 1, 1, 1, 1]);
$x = run('store', 1, $rows, array_replace($store_post, ['config_name' => '']) + vals('1', 'seo_pro', '1', '.htm'));
check('error in another field: nothing saved, the sent SEO values stay on the form', [$x['saved'], errors($x), shown($x['html'])], [[], [], vals('1', 'seo_pro', '1', '.htm')]);
$x = run('store', 1, $rows, array_replace($store_post, ['config_name' => '']) + ['config_seo_url_type' => 'seo_pro']);
check('error in another field: the SEO fields that were not sent show the own settings of the store', shown($x['html']), vals('0', 'seo_pro', '0', ''));
$x = run('store', 1, $rows, $store_post + vals('1', 'seo_pro', '1', '.htm'), ['allowed' => false]);
check('no permission: nothing saved', [$x['saved'], $x['redirect'], $x['data']['error_warning'] ?? null], [[], false, 'Warning: You do not have permission to modify stores!']);
$x = run('store', null, $base, $store_post + ['config_seo_url_type' => 'x']);
check('new store with an invalid value: not created', [$x['added'], $x['saved'], errors($x)], [0, [], ['config_seo_url_type']]);

echo "\n-- store form: an invalid stored record\n";
$broken = $base + [1 => array_replace($own, ['config_seo_url_type' => 'bogus', 'config_seo_url' => 'on']) + ['config_name' => 'Two']];
$x = run('store', 1, $broken);
check('GET: errors at the fields, the default store does not replace the record, nothing written', [errors($x), shown($x['html']), $x['saved'], $x['rows'] === $broken], [['config_seo_url', 'config_seo_url_type'], vals('none', 'none', '0', ''), [], true]);
$x = run('store', 1, $broken, $store_post);
check('POST without the fields: rejected, nothing written', [errors($x), $x['saved'], $x['rows'] === $broken], [['config_seo_url', 'config_seo_url_type'], [], true]);
$x = run('store', 1, $broken, $store_post + ['config_seo_url' => '1', 'config_seo_url_type' => 'seo_pro']);
check('POST with correct values: saved', seo($x['saved'][0][1] ?? []), vals('1', 'seo_pro', '0', ''));
$x = run('store', 1, [0 => array_replace($zero, ['config_seo_url_include_path' => ['1']]), 1 => ['config_name' => 'Two']], $store_post);
check('invalid record of the default store is not replaced by the default value', [errors($x), $x['saved']], [['config_seo_url_include_path'], []]);

echo "\n-- store form: HTML\n";
$x = run('store', 1, $rows, array_replace($store_post, ['config_name' => '']) + ['config_seo_url_postfix' => '"><script>alert(1)</script>&amp;']);
check('sent postfix is printed escaped once', [shown($x['html'])['config_seo_url_postfix'], strpos($x['html'], '<script>alert(1)') === false], ['&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;&amp;amp;', true]);
$x = run('store', 1, $rows, $store_post + ['config_seo_url_postfix' => '"<&amp;']);
check('saved as Request::clean() gives it, like every setting', $x['saved'][0][1]['config_seo_url_postfix'] ?? null, '&quot;&lt;&amp;amp;');
$y = run('store', 1, $x['rows']);
check('saved postfix is shown again without a second layer', shown($y['html'])['config_seo_url_postfix'], '&quot;&lt;&amp;amp;');
$x = run('store', 1, $base + [1 => ['config_seo_url_postfix' => '"><b>x</b>']]);
check('postfix written to the database unescaped is escaped on the form', [shown($x['html'])['config_seo_url_postfix'], strpos($x['html'], '"><b>x</b>') === false], ['&quot;&gt;&lt;b&gt;x&lt;/b&gt;', true]);
$x = run('store', 1, $rows, $store_post + ['config_seo_url_type' => '"><script>alert(2)</script>']);
check('invalid type is not printed', strpos($x['html'], 'alert(2)') === false, true);

echo "\n-- main form\n";
$x = run('main', null, $base);
check('GET shows the settings of the default store', [shown($x['html']), errors($x)], [$zero, []]);
check('GET: one field of each kind', array_map(fn($k) => substr_count($x['html'], 'name="' . $k . '" value="1"') + substr_count($x['html'], 'id="' . $k . '"'), SEO), [1, 1, 1, 1]);
$x = run('main', null, [0 => ['config_name' => 'One']]);
check('GET, nothing stored: the default values', shown($x['html']), vals('0', 'seo_url', '0', ''));
$x = run('main', null, $base, $main_post + vals('0', 'seo_url', '0', ''));
check('valid POST: one save to store 0', [count($x['saved']), $x['saved'][0][0] ?? null, seo($x['saved'][0][1] ?? [])], [1, 0, vals('0', 'seo_url', '0', '')]);
$x = run('main', null, $base, $main_post);
check('POST without the SEO fields keeps the stored settings', seo($x['saved'][0][1] ?? []), $zero);
$x = run('main', null, [0 => $own], $main_post);
check('POST without the SEO fields keeps "0" and ""', seo($x['saved'][0][1] ?? []), $own);
$x = run('main', null, [0 => []], $main_post);
check('POST without the SEO fields, nothing stored: the default values', seo($x['saved'][0][1] ?? []), vals('0', 'seo_url', '0', ''));
foreach ($bad as $key => $values) foreach ($values as $value) {
	$x = run('main', null, $base, $main_post + array_diff_key($own, [$key => 1]) + [$key => $value]);
	$name = "$key = " . json_encode($value);
	check("$name: nothing saved, error at the field, no other value chosen", [$x['saved'], errors($x), shown($x['html'])], [[], [$key], array_replace($own, [$key => $key === 'config_seo_url_postfix' ? '' : 'none'])]);
}
$x = run('main', null, $base, $main_post + vals('x', 'x', 'x', ['x']));
check('four invalid fields: four texts on the form', [errors($x), substr_count($x['html'], 'Use SEO URLs: choose Yes or No!'), substr_count($x['html'], 'SEO type: choose one of the listed types!'), substr_count($x['html'], 'SEO with categories: choose Yes or No!'), substr_count($x['html'], 'SEO postfix must be a text value!'), substr_count($x['html'], '<input type="hidden" name="config_seo_url" value="" />')], [SEO, 1, 1, 1, 1, 1]);
$x = run('main', null, $base, array_replace($main_post, ['config_name' => '']) + ['config_seo_url_postfix' => '"<x>']);
check('error in another field: nothing saved, sent and stored SEO values stay, escaped', [$x['saved'], shown($x['html'])], [[], vals('1', 'seo_pro', '1', '&quot;&lt;x&gt;')]);
$x = run('main', null, $base, $main_post + $own, ['allowed' => false]);
check('no permission: nothing saved', [$x['saved'], $x['redirect']], [[], false]);
$x = run('main', null, [0 => array_replace($zero, ['config_seo_url' => 'on'])]);
check('invalid stored record: error on GET, nothing chosen', [errors($x), shown($x['html'])['config_seo_url'], $x['saved']], [['config_seo_url'], 'none', []]);
$x = run('main', null, [0 => array_replace($zero, ['config_seo_url' => 'on'])], $main_post);
check('invalid stored record: POST without the field is rejected', [errors($x), $x['saved']], [['config_seo_url'], []]);

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);
