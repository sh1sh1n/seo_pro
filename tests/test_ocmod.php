<?php
// 1.7: install.xml together with other modifications, applied by ocmod_sim.php the way Refresh does.
// - the core alone: no operation is ignored, a search that changes add and edit has changed both;
// - an extension that already has HTML H1: only the equivalent operations are skipped, the others still apply;
// - a modification that removes an anchor: the release check fails, a partial application is never a pass;
// - the theme OCTemplates Deals when DEALS_DIR is set (its system/*.ocmod.xml applied first, in the order of the core).
// usage: OC_DIR=... [DEALS_DIR=.../upload] [PHP=php] php test_ocmod.php. Exit code 1 when a check fails.
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
$OC = rtrim(getenv('OC_DIR') ?: '', '/');
if (!$OC) { fwrite(STDERR, "usage: OC_DIR=<opencart-3.0.5.1/upload> [DEALS_DIR=<deals upload>] php test_ocmod.php\n"); exit(2); }
$DEALS = rtrim(getenv('DEALS_DIR') ?: '', '/'); $PHP = getenv('PHP') ?: PHP_BINARY;
$ROOT = dirname(__DIR__); $XML = $ROOT . '/seo_pro_oc3.ocmod/install.xml';
$TMP = sys_get_temp_dir() . '/seo_pro_ocmod_' . getmypid(); @mkdir($TMP);
register_shutdown_function(function () use ($TMP) { exec('rm -rf ' . escapeshellarg($TMP)); });

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
// Applies the XMLs to the core (plus $overlays) with upload/ of the module over it. Returns [exit code, summary, out dir, root].
function sim($name, $xmls, $overlays = []) {
	global $OC, $ROOT, $PHP, $TMP, $XML;
	$root = "$TMP/$name/core"; $out = "$TMP/$name/out"; @mkdir($root, 0777, true);
	foreach (array_merge([$OC], $overlays, [$ROOT . '/seo_pro_oc3.ocmod/upload']) as $dir) exec('cp -R ' . escapeshellarg($dir . '/.') . ' ' . escapeshellarg($root . '/'));
	exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . '/ocmod_sim.php') . ' --check=install.xml ' . escapeshellarg($root) . ' ' . escapeshellarg($out) . ' ' . escapeshellarg($OC . '/system/modification.xml') . ' ' . implode(' ', array_map('escapeshellarg', array_merge($xmls, [$XML]))) . ' 2>&1', $lines, $code);
	$summary = json_decode(substr((string)end($lines), 8), true);
	return [$code, $summary, $out, $root, $lines];
}
function file_of($x, $path) { return is_file($x[2] . '/' . $path) ? file_get_contents($x[2] . '/' . $path) : file_get_contents($x[3] . '/' . $path); }
function lint($x) { global $PHP; $bad = []; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($x[2], FilesystemIterator::SKIP_DOTS)) as $f) if (substr($f, -4) === '.php') { exec(escapeshellarg($PHP) . ' -l ' . escapeshellarg((string)$f) . ' 2>&1', $o, $c); if ($c) $bad[] = (string)$f; } return $bad; }
function xml($name, $files) { global $TMP; $s = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<modification><name>$name</name><code>$name</code><version>1</version>\n";
	foreach ($files as $path => $ops) { $s .= "<file path=\"$path\">\n"; foreach ($ops as $op) { [$search, $add, $pos] = $op; $index = isset($op[3]) ? " index=\"$op[3]\"" : ''; $s .= "<operation error=\"skip\"><search$index><![CDATA[$search]]></search><add position=\"$pos\"><![CDATA[$add]]></add></operation>\n"; } $s .= "</file>\n"; }
	file_put_contents($f = "$TMP/$name.ocmod.xml", $s . '</modification>'); return $f; }
const INSERT_H1 = "meta_h1 = '\" . \$this->db->escape(\$value['meta_h1']) . \"'";

echo "-- the core alone\n";
$x = sim('core', []);
$s = $x[1]['install.xml'] ?? [];
check('every operation found, none ignored, no file missing', [$x[0], $s['not_found'] ?? null, $s['ignored'] ?? null, $s['missing_files'] ?? null], [0, 0, 0, []]);
foreach (['product', 'category', 'information'] as $e) {
	$model = file_of($x, "admin/model/catalog/$e.php");
	check("$e model: meta_h1 in the INSERT of add and of edit", substr_count($model, INSERT_H1), 2);
	check("$e model: H1 prepared in add and in edit, before the first query of each", preg_match_all('~public function (add|edit)' . ucfirst($e) . '\([^)]*\) \{(?:(?!\$this->db->query).)*Seo_pro_schema::prepareH1~s', $model), 2);
	check("$e model: meta_h1 in get*Descriptions once", preg_match_all("~'meta_h1'\s*=>~", $model), 1);
	check("$e form: one H1 field", substr_count(file_of($x, "admin/view/template/catalog/{$e}_form.twig"), "_description[{{ language.language_id }}][meta_h1]\""), 1);
	foreach (['en-gb', 'ru-ru'] as $l) check("$e language $l: the strings", substr_count(file_of($x, "admin/language/$l/catalog/$e.php"), "\$_['error_seo_pro_h1_schema']"), 1);
}
$m = file_of($x, 'admin/model/catalog/manufacturer.php');
check('manufacturer model: saved in add and in edit, deleted, read', [substr_count($m, 'Seo_pro_schema::prepareManufacturer'), substr_count($m, 'Seo_pro_schema::saveManufacturer'), substr_count($m, 'Seo_pro_schema::deleteManufacturer'), substr_count($m, 'function getManufacturerDescriptions(')], [2, 2, 1, 1]);
check('manufacturer model: the descriptions are saved before the core cache reset', preg_match_all('~saveManufacturer(?:(?!function ).)*\$this->cache->delete\(\'manufacturer\'\)~s', $m), 2);
check('startup of admin and catalog call the library', [substr_count(file_of($x, 'admin/controller/startup/startup.php'), 'Seo_pro_schema::startup('), substr_count(file_of($x, 'catalog/controller/startup/startup.php'), 'Seo_pro_schema::startup(')], [1, 1]);
check('modified PHP files pass php -l', lint($x), []);

echo "\n-- an extension that already has HTML H1 of products\n";
$foreign = xml('foreign_h1', [
	'admin/view/template/catalog/product_form.twig' => [['<label class="col-sm-2 control-label" for="input-meta-description{{ language.language_id }}">', '<input type="text" name="product_description[{{ language.language_id }}][meta_h1]" value="" />', 'before']],
	'admin/model/catalog/product.php' => [["meta_title = '\" . \$this->db->escape(\$value['meta_title']) . \"',", "meta_title = '\" . \$this->db->escape(\$value['meta_title']) . \"', " . INSERT_H1 . ",", 'replace'], ["'meta_title'       => \$result['meta_title'],", "'meta_h1'  => \$result['meta_h1'],", 'after']]]);
$x = sim('foreign', [$foreign]); $s = $x[1]['install.xml'] ?? [];
check('the release check passes, exactly the three equivalent operations are skipped', [$x[0], $s['not_found'] ?? null, $s['ignored'] ?? null], [0, 0, 3]);
check('product: one H1 field, one meta_h1 in each INSERT, one array key', [substr_count(file_of($x, 'admin/view/template/catalog/product_form.twig'), '[meta_h1]"'), substr_count(file_of($x, 'admin/model/catalog/product.php'), INSERT_H1), preg_match_all("~'meta_h1'\s*=>~", file_of($x, 'admin/model/catalog/product.php'))], [1, 2, 1]);
check('product: our preparation of H1 still runs', substr_count(file_of($x, 'admin/model/catalog/product.php'), 'Seo_pro_schema::prepareH1'), 2);
check('categories and information pages are not affected by the skip', [substr_count(file_of($x, 'admin/model/catalog/category.php'), INSERT_H1), substr_count(file_of($x, 'admin/view/template/catalog/information_form.twig'), '[meta_h1]"')], [2, 1]);
check('php -l', lint($x), []);

echo "\n-- an extension that has H1 in one of the two INSERTs only: the other one still gets it\n";
// The INSERT lines of the core: the first one is in add*, the second one in edit*
$tail = "meta_keyword = '\" . \$this->db->escape(\$value['meta_keyword']) . \"'\");";
foreach (['add' => 0, 'edit' => 1] as $where => $index) {
	$ops = [];
	foreach (['product', 'category', 'information'] as $e) $ops["admin/model/catalog/$e.php"] = $where === 'add'
		? [["meta_title = '\" . \$this->db->escape(\$value['meta_title']) . \"',", "meta_title = '\" . \$this->db->escape(\$value['meta_title']) . \"', " . INSERT_H1 . ",", 'replace', $index]]
		: [[$tail, "meta_keyword = '\" . \$this->db->escape(\$value['meta_keyword']) . \"', " . INSERT_H1 . "\");", 'replace', $index]];
	$x = sim("partial_$where", [xml("partial_h1_$where", $ops)]); $s = $x[1]['install.xml'] ?? [];
	check("H1 in $where only: the release check passes, nothing ignored, nothing missing", [$x[0], $s['not_found'] ?? null, $s['ignored'] ?? null], [0, 0, 0]);
	foreach (['product', 'category', 'information'] as $e) {
		preg_match_all('~^.*INSERT INTO " \. DB_PREFIX \. "' . $e . '_description SET .*$~m', file_of($x, "admin/model/catalog/$e.php"), $ins);
		check("H1 in $where only, $e: two INSERTs, each with exactly one meta_h1", array_map(fn($l) => substr_count($l, INSERT_H1), $ins[0]), [1, 1]);
	}
	check("H1 in $where only: php -l", lint($x), []);
	exec('OC_DIR=' . escapeshellarg($OC) . ' ' . escapeshellarg($PHP) . ' ' . escapeshellarg(__DIR__ . '/test_h1.php') . ' ' . escapeshellarg($x[2]) . ' 2>&1', $h1, $code);
	check("H1 in $where only: test_h1.php (add, edit, copy, partial input) passes on the result", [$code, end($h1)], [0, 'failed: 0']); $h1 = [];
}

echo "\n-- an extension that already has descriptions of manufacturers\n";
$foreign = xml('foreign_md', ['admin/view/template/catalog/manufacturer_form.twig' => [['<div class="tab-pane" id="tab-seo">', '<textarea name="manufacturer_description[{{ language.language_id }}][description]"></textarea>', 'before']]]);
$x = sim('foreign_md', [$foreign]); $s = $x[1]['install.xml'] ?? [];
check('the tab and the pane are skipped together, nothing else', [$x[0], $s['ignored'] ?? null, substr_count(file_of($x, 'admin/view/template/catalog/manufacturer_form.twig'), 'tab-seo-pro-description')], [0, 2, 0]);

echo "\n-- a modification that removes an anchor\n";
foreach (['catalog/controller/product/product.php' => "\$data['heading_title'] = \$product_info['name'];", 'admin/model/catalog/manufacturer.php' => "public function deleteManufacturer(\$manufacturer_id) {", 'admin/view/template/catalog/category_form.twig' => '<label class="col-sm-2 control-label" for="input-meta-description{{ language.language_id }}">'] as $path => $anchor) {
	$x = sim('broken_' . md5($path), [xml('breaks_' . md5($path), [$path => [[$anchor, '/* changed by another modification */', 'replace']]])]); $s = $x[1]['install.xml'] ?? [];
	check("$path: the release check fails, the miss is counted", [$x[0], $s['not_found'] ?? null, $x[1]['ok'] ?? null], [1, 1, false]);
}

echo "\n-- OCTemplates Deals\n";
if (!$DEALS || !is_dir($DEALS . '/system')) {
	echo "skip DEALS_DIR is not set: the combination with the theme is not checked\n";
} else {
	$xmls = glob($DEALS . '/system/*.ocmod.xml'); sort($xmls);
	$x = sim('deals', $xmls, [$DEALS]); $s = $x[1]['install.xml'] ?? [];
	check('Deals first, then install.xml: every operation of install.xml found, none ignored', [$x[0], $s['not_found'] ?? null, $s['ignored'] ?? null, $s['missing_files'] ?? null], [0, 0, 0, []]);
	if ($x[0]) echo implode("\n", preg_grep('~NOT FOUND|NO FILE~', $x[4])), "\n";
	check('Deals: php -l of the modified files', lint($x), []);
	foreach (['product/product' => 'product_info', 'product/category' => 'category_info', 'product/manufacturer' => 'manufacturer_info'] as $c => $info) {
		$src = file_of($x, "catalog/controller/$c.php");
		$h1 = strpos($src, "\$data['heading_title'] = \${$info}['meta_h1'];");
		preg_match_all("~\\\$data\['heading_title'\] = (?!\\\${$info}\['(?:meta_h1|name)'\])[^;]+;~", substr($src, $h1 ?: 0), $later);
		echo "info $c: assignments of heading_title after ours: " . (count($later[0]) ? implode(' | ', $later[0]) : 'none') . "\n";
	}
}

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);
