<?php
// Faithful port of admin/controller/marketplace/modification.php::refresh() (OC 3.0.5.1), file part only.
// usage: php ocmod_sim.php [--check=<xml basename>]... <root> <out> <xml>...
// The last line is "SUMMARY <json>": per XML the number of applied operations, of operations whose search was not found,
// of ignored ones (ignoreif), the missing files and the missing optional files (language packs other than en-gb).
// Exit code 1 when an operation was not found, a file other than an optional one is missing or an XML was aborted.
// With --check only the named XMLs decide the exit code: a third-party XML may have misses of its own.
$args = array_slice($argv, 1); $check = [];
foreach ($args as $i => $a) if (strpos($a, '--check=') === 0) { $check[] = substr($a, 8); unset($args[$i]); }
$args = array_values($args);
if (count($args) < 3) { fwrite(STDERR, "usage: php ocmod_sim.php [--check=<xml basename>]... <root> <out> <xml>...\n"); exit(2); }
[$root, $out] = $args; $xmls = array_slice($args, 2);
$summary = [];
$root = rtrim($root, '/') . '/';
$modification = []; $original = []; $log = [];
foreach ($xmls as $xmlfile) {
	$dom = new DOMDocument('1.0', 'UTF-8'); $dom->preserveWhiteSpace = false; $dom->loadXml(file_get_contents($xmlfile));
	$log[] = 'MOD: ' . basename($xmlfile);
	$summary[basename($xmlfile)] = ['applied' => 0, 'not_found' => 0, 'ignored' => 0, 'aborted' => false, 'missing_files' => [], 'missing_optional_files' => []];
	$sum = &$summary[basename($xmlfile)];
	$recovery = $modification;
	foreach ($dom->getElementsByTagName('modification')->item(0)->getElementsByTagName('file') as $file) {
		$operations = $file->getElementsByTagName('operation');
		foreach (explode('|', str_replace("\\", '/', $file->getAttribute('path'))) as $fpath) {
			if (!preg_match('~^(catalog|admin|system)/~', $fpath)) continue;
			$found = glob($root . $fpath, GLOB_BRACE) ?: [];
			if (!$found) { $log[] = "NO FILE: $fpath"; $sum[preg_match('~^(admin|catalog)/language/(?!en-gb/)~', $fpath) ? 'missing_optional_files' : 'missing_files'][] = $fpath; }
			foreach ($found as $f) {
				$key = substr($f, strlen($root));
				if (!isset($modification[$key])) { $c = preg_replace('~\r?\n~', "\n", file_get_contents($f)); $modification[$key] = $c; $original[$key] = $c; $log[] = "FILE: $key"; }
				foreach ($operations as $operation) {
					$error = $operation->getAttribute('error');
					$ignoreif = $operation->getElementsByTagName('ignoreif')->item(0);
					if ($ignoreif) {
						if ($ignoreif->getAttribute('regex') != 'true') { if (strpos($modification[$key], $ignoreif->textContent) !== false) { $log[] = "IGNOREIF hit"; $sum['ignored']++; continue; } }
						elseif (preg_match($ignoreif->textContent, $modification[$key])) { $sum['ignored']++; continue; }
					}
					$status = false;
					$se = $operation->getElementsByTagName('search')->item(0); $ae = $operation->getElementsByTagName('add')->item(0);
					if (!$se || !$ae) continue;
					if ($se->getAttribute('regex') != 'true') {
						$search = $se->textContent; $trim = $se->getAttribute('trim'); $index = $se->getAttribute('index');
						if (!$trim || $trim == 'true') $search = trim($search);
						$add = $ae->textContent; $trim = $ae->getAttribute('trim'); $position = $ae->getAttribute('position'); $offset = $ae->getAttribute('offset');
						if ($offset == '') $offset = 0;
						if ($trim == 'true') $add = trim($add);
						$indexes = $index !== '' ? explode(',', $index) : [];
						$i = 0; $lines = explode("\n", $modification[$key]); $hits = 0;
						for ($line_id = 0; $line_id < count($lines); $line_id++) {
							$line = $lines[$line_id]; $match = false;
							if (stripos($line, $search) !== false) { if (!$indexes || in_array($i, $indexes)) $match = true; $i++; }
							if ($match) {
								switch ($position) {
									default: case 'replace':
										if ($offset < 0) { array_splice($lines, $line_id + $offset, abs($offset) + 1, [str_replace($search, $add, $line)]); $line_id -= $offset; }
										else array_splice($lines, $line_id, $offset + 1, [str_replace($search, $add, $line)]);
										break;
									case 'before': $nl = explode("\n", $add); array_splice($lines, $line_id - $offset, 0, $nl); $line_id += count($nl); break;
									case 'after': $nl = explode("\n", $add); array_splice($lines, ($line_id + 1) + $offset, 0, $nl); $line_id += count($nl); break;
								}
								$status = true; $hits++;
							}
						}
						$modification[$key] = implode("\n", $lines);
						if ($status) $log[] = "  HIT x$hits: " . substr($search, 0, 90);
					} else {
						$search = trim($se->textContent); $limit = $se->getAttribute('limit') ?: -1; $replace = trim($ae->textContent);
						if (preg_match_all($search, $modification[$key], $m)) $status = true;
						$modification[$key] = preg_replace($search, $replace, $modification[$key], (int)$limit);
					}
					if ($status) $sum['applied']++;
					if (!$status) {
						$log[] = "  NOT FOUND ($error): " . substr($search, 0, 90); $sum['not_found']++;
						if ($error == 'abort') { $modification = $recovery; $log[] = 'ABORT'; $sum['aborted'] = true; break 3; }
						elseif ($error == 'skip') continue;
						else { $log[] = '  OPERATIONS ABORTED'; $sum['aborted'] = true; break; }
					}
				}
			}
		}
	}
	unset($sum);
}
foreach ($modification as $key => $value) {
	if ($original[$key] != $value) { @mkdir(dirname($out . '/' . $key), 0777, true); file_put_contents($out . '/' . $key, $value); }
}
echo implode("\n", $log), "\n";
$failed = false;
foreach ($summary as $name => $sum) if ((!$check || in_array($name, $check, true)) && ($sum['not_found'] || $sum['missing_files'] || $sum['aborted'] || !$sum['applied'])) $failed = true;
foreach ($check as $name) if (!isset($summary[$name])) $failed = true;
echo 'SUMMARY ', json_encode(['ok' => !$failed] + $summary, JSON_UNESCAPED_SLASHES), "\n";
exit($failed ? 1 : 0);
