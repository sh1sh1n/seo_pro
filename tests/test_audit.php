<?php
// audit_seo_url.php --tsv: a file that cannot be read in full is never reported as "no unsupported records".
// usage: php test_audit.php. Exit code 1 when a check fails.
$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
function audit($content) {
	$file = tempnam(sys_get_temp_dir(), 'seo'); file_put_contents($file, $content);
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/audit_seo_url.php') . ' --tsv ' . escapeshellarg($file) . ' 2>&1', $lines, $code); unlink($file);
	return [$code, count(preg_grep('~^unsupported\t~', $lines)), end($lines) ?: ''];
}
$good = "1\t0\t1\tproduct_id=1\ttyre\n2\t0\t1\tinformation/contact\tcontact\n"; $blog = "3\t0\t1\tblog_id=3\tblog\n"; $bom = "\xEF\xBB\xBF";
check('supported records', audit($good), [0, 0, 'records: 2; {"product_id":1,"route":1}']);
check('unsupported record', array_slice(audit($good . $blog), 0, 2), [1, 1]);
check('unsupported record first, with a BOM', array_slice(audit($bom . $blog . $good), 0, 2), [1, 1]);
check('BOM, header, CRLF, empty lines', audit($bom . "seo_url_id\tstore_id\tlanguage_id\tquery\tkeyword\r\n\r\n" . str_replace("\n", "\r\n", $blog . $good) . "\r\n"), [1, 1, 'records: 3; {"product_id":1,"route":1,"unsupported":1}']);
check('empty query and empty keyword are records', array_slice(audit("1\t0\t1\t\tk\n2\t0\t1\tproduct_id=1\t\n"), 0, 2), [1, 1]);
check('empty export', audit(''), [0, 0, 'records: 0; []']);
check('only a header', audit("seo_url_id\tstore_id\tlanguage_id\tquery\tkeyword\n"), [0, 0, 'records: 0; []']);
foreach (['four fields' => "1\t0\t1\tblog_id=3\n", 'six fields' => "1\t0\t1\tblog_id=3\tblog\tx\n", 'id is not a number' => "x\t0\t1\tblog_id=3\tblog\n", 'store is not a number' => "1\t\t1\tblog_id=3\tblog\n",
	'spaces instead of tabs' => "1 0 1 blog_id=3 blog\n", 'header in the middle' => $good . "seo_url_id\tstore_id\tlanguage_id\tquery\tkeyword\n", 'not UTF-8' => "1\t0\t1\tproduct_id=1\t\xFF\xFE\n", 'BOM twice' => $bom . $bom . $blog] as $name => $content) {
	[$code, , $last] = audit($good . $content);
	check("$name: exit 2, no result line", [$code, strpos($last, 'records:') === false], [2, true]);
}
[$code, , $last] = audit($good . "broken\n" . $blog); check('the wrong line is named', [$code, strpos($last, 'line 3:') === 0], [2, true]);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/audit_seo_url.php') . ' --tsv /nonexistent/file 2>&1', $o, $code); check('missing file: exit 2', $code, 2);

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);
