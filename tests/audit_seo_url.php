<?php
// Read-only check before the upgrade to 1.6.3: lists the seo_url records the module no longer opens (they answer 404).
// The records are classified by the controller itself. Nothing is written to the database.
// usage: php audit_seo_url.php <path to the config.php of the shop>
//    or: php audit_seo_url.php --tsv <file>   (lines "seo_url_id<TAB>store_id<TAB>language_id<TAB>query<TAB>keyword", for example an export)
//        TSV: UTF-8, a BOM is allowed, LF or CRLF. Empty lines are skipped. The first line may be the header "seo_url_id<TAB>...".
//        Every other line needs five fields with numeric ids: one wrong line stops the check with exit code 2, it is never skipped.
// Exit code 0: every record is supported; 1: there are unsupported records; 2: the check could not run.
error_reporting(E_ALL);
abstract class Controller {}
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php';
$classify = new ReflectionMethod('ControllerExtensionStartupSeoPro', 'classifyQuery'); if (PHP_VERSION_ID < 80100) $classify->setAccessible(true);
$controller = (new ReflectionClass('ControllerExtensionStartupSeoPro'))->newInstanceWithoutConstructor();

$rows = [];
if (($argv[1] ?? '') === '--tsv' && isset($argv[2]) && is_file($argv[2])) {
	$text = file_get_contents($argv[2]);
	if ($text === false) { fwrite(STDERR, "cannot read {$argv[2]}\n"); exit(2); }
	if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
	if (!mb_check_encoding($text, 'UTF-8')) { fwrite(STDERR, "the file is not UTF-8\n"); exit(2); }
	$first = true;
	foreach (explode("\n", $text) as $n => $line) {
		$line = rtrim($line, "\r");
		if ($line === '') continue;
		$f = explode("\t", $line);
		$header = $first && $f[0] === 'seo_url_id'; $first = false;
		if ($header) continue;
		if (count($f) !== 5 || !ctype_digit($f[0]) || !ctype_digit($f[1]) || !ctype_digit($f[2])) { fwrite(STDERR, 'line ' . ($n + 1) . ": expected seo_url_id, store_id, language_id, query, keyword separated by tabs, with numeric ids\n"); exit(2); }
		$rows[] = array_combine(['seo_url_id', 'store_id', 'language_id', 'query', 'keyword'], $f);
	}
} elseif (isset($argv[1]) && is_file($argv[1])) {
	require $argv[1];
	try {
		$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)(defined('DB_PORT') ? DB_PORT : 3306)); $db->set_charset('utf8');
		$rows = $db->query("SELECT seo_url_id, store_id, language_id, `query`, keyword FROM `" . DB_PREFIX . "seo_url` ORDER BY store_id, language_id, seo_url_id")->fetch_all(MYSQLI_ASSOC);
	} catch (\Throwable $e) { fwrite(STDERR, 'database: ' . $e->getMessage() . "\n"); exit(2); }
} else { fwrite(STDERR, "usage: php audit_seo_url.php <config.php> | --tsv <file>\n"); exit(2); }

$kinds = []; $bad = 0;
foreach ($rows as $row) {
	$item = $classify->invoke($controller, (string)$row['query']);
	$kind = $item === null ? 'unsupported' : $item['type']; $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
	if ($item === null) { $bad++; echo "unsupported\tseo_url_id={$row['seo_url_id']}\tstore_id={$row['store_id']}\tlanguage_id={$row['language_id']}\tquery=" . json_encode($row['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\tkeyword=" . json_encode($row['keyword'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; }
}
ksort($kinds);
echo 'records: ' . count($rows) . '; ' . json_encode($kinds) . "\n";
exit($bad ? 1 : 0);
