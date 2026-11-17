<?php
// Own server per run, random port and handshake. Never accepts another process as a successful run.
error_reporting(E_ALL);
// BEGIN STAND HTTP
function standHttpRequest($url, $method = 'GET', $xhr = false) {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $xhr ? ['X-Requested-With: XMLHttpRequest'] : []]);
    $body = curl_exec($curl);
    if ($body === false) { throw new RuntimeException('HTTP transport failed'); }
    $result = ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'location' => (string)curl_getinfo($curl, CURLINFO_REDIRECT_URL), 'body' => $body, 'ms' => curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME) * 1000];
    return $result;
}
function standDocument($body, $base) {
    $canonical = '-'; $h1 = '-';
    if (preg_match('~<link\b[^>]*rel=["\']canonical["\'][^>]*>~i', $body, $m) && preg_match('~href=["\']([^"\']+)["\']~', $m[0], $href)) $canonical = str_replace($base, '', html_entity_decode($href[1], ENT_QUOTES, 'UTF-8'));
    if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $body, $m)) $h1 = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    return [$canonical, $h1];
}
function standTarget($url) {
    $url = str_replace('/desktops/htc-touch-hd.html', '/htc-touch-hd.html', $url);
    if (preg_match('~/(?:[^/?]+/)*load-test-product-(\d+)\.html~', $url, $m) && (int)$m[1] % 7 === 0) $url = str_replace($m[0], '/load-test-product-' . $m[1] . '.html', $url);
    if (strpos($url, 'htc-touch-hd.html') !== false || strpos($url, 'load-test-product-') !== false) {
        $parts = explode('?', $url, 2);
        if (isset($parts[1])) { parse_str($parts[1], $params); foreach (['manufacturer_id','sort','order','page','limit'] as $key) unset($params[$key]); $url = $parts[0] . ($params ? '?' . http_build_query($params, '', '&') : ''); }
    }
    return $url;
}
function standManifestLines($dir,$path) {
    if (defined('SEO_PRO_STAND_MANIFEST')) { $files=json_decode(base64_decode(SEO_PRO_STAND_MANIFEST),true); if(isset($files[$path])) return explode("\n",$files[$path]); }
    if (!is_file($dir.'/'.$path)) throw new RuntimeException('missing stand manifest '.$path);
    return file($dir.'/'.$path);
}
function standHttpRegression($build, $dir, $scenario = false) {
    if (!in_array($build, ['baseline','candidate'], true)) throw new RuntimeException('choose baseline or candidate explicitly');
    $base = 'https://test.mozap.ru'; $checks = 0; $failed = 0;
    $assert = function ($ok, $label) use (&$checks, &$failed) { $checks++; if (!$ok) { $failed++; echo "FAIL $label\n"; } };
    if (!$scenario) foreach (standManifestLines($dir,'scripts/suite.sh') as $line) {
        if (!preg_match('~^t \'([^\']*)\' ([\'"])(.*?)\2(.*)$~', trim($line), $m)) continue;
        $uri = $m[1]; $expected = str_replace('$B', $base, $m[3]); $method = strpos($m[4], '-X POST') !== false ? 'POST' : 'GET';
        if ($build === 'candidate' && $method === 'GET') {
            $expected = standTarget($expected);
            if ($uri === '/htc-touch-hd.html') $expected = '200';
            if ($uri === '/desktops/htc-touch-hd.html') $expected = '301 ' . $base . '/htc-touch-hd.html';
        }
        $r = standHttpRequest($base . $uri, $method);
        $assert(trim($r['status'] . ' ' . $r['location']) === $expected, "$method $uri got=" . trim($r['status'] . ' ' . $r['location']) . " expected=$expected");
    }
    $legacy = 0; $skipped = 0;
    foreach (['addr','canon3','mixed3'] as $set) {
        $path = 'baseline_cdecbdc/' . $set . '_cdecbdc.txt';
        $afterCleanup = false;
        foreach (standManifestLines($dir,$path) as $line) {
            if ($set === 'canon3' && strpos($line, 'rows after:') === 0) $afterCleanup = true;
            if (!preg_match('~^(GET|POST|AJAX)\s+(\S+)\s+(?:\[\d+\]\s+)?(.*?)\s+\| canon=(.*?) \| h1=(.*)$~', trim($line), $m)) continue;
            $temporary = ($set === 'canon3' && !$afterCleanup) || (!$afterCleanup && preg_match('~load-blog-(?:sub|nested)|/search(?:/|\?|$)|route=product/search~', $line) === 1);
            if ($temporary !== $scenario) { if ($temporary && !$scenario) $skipped++; continue; }
            [$all,$label,$uri,$expectedChain,$expectedCanonical,$expectedH1] = $m;
            if ($build === 'candidate') { $expectedChain = standTarget($expectedChain); $expectedCanonical = standTarget($expectedCanonical); }
            $url = $base . $uri; $chain = []; $r = null;
            for ($n = 0; $n < 6; $n++) {
                $r = standHttpRequest($url, $n === 0 && $label === 'POST' ? 'POST' : 'GET', $n === 0 && $label === 'AJAX');
                $chain[] = (string)$r['status']; if ($r['location'] === '') break;
                if (strpos($r['location'], $base . '/') !== 0) throw new RuntimeException('unexpected redirect host');
                $chain[] = '-> ' . substr($r['location'], strlen($base)); $url = $r['location'];
            }
            // On an already short candidate address the old 301 entry intentionally collapses to 200.
            if ($build === 'candidate' && preg_match('~^301 -> (\S+) 200$~', $expectedChain, $target) && $target[1] === $uri) $expectedChain = '200';
            [$canonical,$h1] = standDocument($r['body'], $base); $actual = implode(' ', $chain);
            $assert($actual === $expectedChain, "$set $label $uri chain=$actual expected=$expectedChain");
            $assert($canonical === $expectedCanonical, "$set $uri canonical=$canonical expected=$expectedCanonical");
            $assert(strncmp($h1, $expectedH1, strlen($expectedH1)) === 0, "$set $uri H1"); $legacy++;
        }
    }
    echo "HTTP stand build=$build checks=$checks legacy_requests=$legacy failed=$failed\n";
    if ($skipped) echo "not run temporary historical requests=$skipped: run --stand-scenarios with backups/finally\n";
    if ($failed) throw new RuntimeException('stand HTTP expectations differ');
}

function standUri($url) { $p=parse_url($url); return ($p['path']??'/').(isset($p['query'])?'?'.$p['query']:''); }
function standVerifyProducts($db,$build) {
    $base='https://test.mozap.ru'; $p=DB_PREFIX; $kw=[]; $paths=[];
    foreach($db->query("SELECT `query`, keyword FROM {$p}seo_url WHERE store_id=0 AND language_id=1 AND `query` LIKE 'category_id=%' ORDER BY seo_url_id")->rows as $r)$kw[(int)substr($r['query'],12)]=$r['keyword'];
    foreach($db->query("SELECT category_id, GROUP_CONCAT(path_id ORDER BY level SEPARATOR '_') AS path FROM {$p}category_path GROUP BY category_id")->rows as $r)$paths[(int)$r['category_id']]=explode('_',$r['path']);
    mt_srand(20261115);$checks=0;$failed=0;$times=[];
    for($i=0;$i<300;$i++) {
        $n=mt_rand(1,100500);$id=1000000+$n;
        $rows=$db->query("SELECT category_id, main_category FROM {$p}product_to_category WHERE product_id=$id ORDER BY main_category DESC,category_id")->rows;
        $mains=array_values(array_filter($rows,function($r){return (int)$r['main_category']===1;}));
        $candidates=$build==='candidate' ? (count($mains)===1?[(int)$mains[0]['category_id']]:[]) : ($mains?[(int)$mains[0]['category_id']]:array_map(function($r){return (int)$r['category_id'];},$rows));
        $expected=[];foreach($candidates as $category)$expected[]=$base.'/'.implode('/',array_map(function($id)use($kw){return rawurlencode($kw[$id]);},$paths[$category])).'/load-test-product-'.$n.'.html';
        if(!$expected)$expected=[$base.'/load-test-product-'.$n.'.html'];
        foreach(['/index.php?route=product/product&product_id='.$id,'/load-test-product-'.$n.'.html','/desktops/load-test-product-'.$n.'.html'] as $uri) {
            $r=standHttpRequest($base.$uri);$times[]=$r['ms'];$checks++;
            $same=in_array($base.$uri,$expected,true);$ok=$same?($r['status']===200&&$r['location']===''):($r['status']===301&&in_array($r['location'],$expected,true));
            if(!$ok){$failed++;echo "FAIL sample $uri status={$r['status']} location={$r['location']}\n";}
        }
        if($i%10===0){$r=standHttpRequest($expected[0]);[$canon,$h1]=standDocument($r['body'],$base);$checks++;$h1Expected='Load test product '.$n.($n%10===0?' (H1)':'');if($r['status']!==200||!in_array($base.$canon,$expected,true)||$h1!==$h1Expected){$failed++;echo "FAIL sample card id=$id\n";}}
        if(($i+1)%50===0)echo 'sample progress products='.($i+1)."/300\n";
    }
    sort($times);echo "load sample build=$build products=300 checks=$checks failed=$failed TTFB_median_ms=".$times[intdiv(count($times),2)].' p95_ms='.$times[(int)(count($times)*.95)]."\n";
    if($failed)throw new RuntimeException('load-product baseline differs');
}
// END STAND HTTP
if (in_array('--stand-baseline', $argv ?? [], true) || in_array('--stand-candidate', $argv ?? [], true)) {
    // Read-only HTTP here. The MySQL harness verifies installed hashes/cache and orchestrates mutations.
    $build = in_array('--stand-candidate', $argv, true) ? 'candidate' : 'baseline';
    if (getenv('SEO_PRO_STAND_VERIFIED_BUILD') !== $build) { echo "not run: verify installed build and effective file cache first\n"; exit(2); }
    try { standHttpRegression($build, rtrim((string)getenv('SEO_PRO_STAND_TESTS_DIR'), '/')); }
    catch (Throwable $e) { echo 'FAIL ' . $e->getMessage() . "\n"; exit(1); }
    exit(0);
}
$oc = getenv('OC_DIR'); if (!$oc) { echo "not run: set OC_DIR\n"; exit(2); }
$run = bin2hex(random_bytes(10)); $port = random_int(18000, 29000); $log = tempnam(sys_get_temp_dir(), 'navigation_http_');
$env = array_merge(getenv(), ['SEO_PRO_HTTP_RUN_ID' => $run]);
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/cases_product_navigation.php'];
$process = proc_open($command, [0 => ['pipe','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], $pipes, dirname(__DIR__), $env);
$failed = 0; $checks = 0;
function httpCheck($label, $got, $expected) { global $failed, $checks; $checks++; if ($got !== $expected) { $failed++; echo "FAIL $label got=" . json_encode($got) . ' expected=' . json_encode($expected) . "\n"; } }
function httpRequest($base, $uri, $method = 'GET', $fixture = '', $xhr = false) {
    $headers = ["X-Fixture: $fixture"]; if ($xhr) $headers[] = 'X-Requested-With: XMLHttpRequest';
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]];
    $body = file_get_contents($base . $uri, false, stream_context_create($options));
    $response = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : get_defined_vars()['http_response_header']; $out = ['status' => (int)explode(' ', $response[0])[1], 'body' => $body];
    foreach ($response as $header) if (strpos($header, ':') !== false) { [$key,$value] = explode(':', $header, 2); $out[strtolower($key)] = trim($value); }
    return $out;
}
try {
    fclose($pipes[0]); $base = 'http://127.0.0.1:' . $port; $ready = false;
    for ($i = 0; $i < 50; $i++) { if (!proc_get_status($process)['running']) break; $body = @file_get_contents($base . '/__ping__'); if ($body === 'navigation ' . $run) { $ready = true; break; } usleep(100000); }
    if (!$ready) throw new RuntimeException('own HTTP server did not start');
    $canonical = 'http://shop.test/moto/enduro/tyre.html';
    foreach ([false,true] as $xhr) foreach (['GET','HEAD'] as $method) {
        foreach ([['/moto/enduro/tyre.html',200,0,1], ['/index.php?route=product/product&product_id=1',301,1,0], ['/tyre.html?manufacturer_id=5&page=2&utm_source=a%26b',301,1,0], ['/tyre.html?manufacturer_id=6',404,1,0], ['/tyre.html?manufacturer_id=0',404,1,0], ['/tyre.html?manufacturer_id=x',404,1,0], ['/tyre.html?manufacturer_id%5B%5D=5',404,1,0], ['/unknown/tyre.html',404,0,0], ['/enduro/moto/tyre.html',404,1,0]] as [$uri,$status,$calls,$card]) {
            $x = httpRequest($base,$uri,$method,'',$xhr);
            httpCheck("$method $uri XHR=$xhr status", $x['status'], $status);
            httpCheck('startup getProduct', $x['x-startup-product-calls'] ?? null, (string)$calls);
            httpCheck('card entered', $x['x-card-entered'] ?? null, (string)$card);
            httpCheck('notices', $x['x-notices'] ?? null, '0');
            if ($method === 'HEAD') httpCheck('HEAD body', $x['body'], '');
            if ($status === 301) httpCheck('one final target', $x['location'] ?? null, $canonical . (strpos($uri,'utm_source') !== false ? '?utm_source=a%26b' : ''));
        }
    }
    foreach (['POST','PUT','PATCH','DELETE'] as $method) { $x = httpRequest($base,'/index.php?route=product/product&product_id=1',$method); httpCheck("$method no redirect", isset($x['location']), false); httpCheck("$method no startup model", $x['x-startup-product-calls'] ?? null, '0'); }
    foreach (['no-main' => 'http://shop.test/tyre.html', 'no-keyword' => 'http://shop.test/index.php?route=product/product&path=10_11&product_id=1', 'no-category-keyword' => 'http://shop.test/index.php?route=product/product&path=10_11&product_id=1', 'old-category' => 'http://shop.test/moto/tyre.html'] as $fixture => $target) {
        $initial = in_array($fixture, ['no-keyword', 'no-category-keyword'], true) ? '/index.php?route=product/product&product_id=1&page=2' : '/moto/enduro/tyre.html';
        $x = httpRequest($base,$initial,'GET',$fixture); httpCheck("$fixture final redirect", $x['location'] ?? null, $target);
        $parts = parse_url($target); $uri = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $x = httpRequest($base,$uri,'GET',$fixture); httpCheck("$fixture target stable", $x['status'], 200); httpCheck("$fixture target model", $x['x-startup-product-calls'] ?? null, '0');
    }
    foreach (['/moto/enduro/tyre.html','/tyre.html'] as $uri) { $x = httpRequest($base,$uri,'GET','unavailable'); httpCheck('unavailable 404', $x['status'], 404); httpCheck('unavailable no redirect', isset($x['location']), false); }
    $x = httpRequest($base,'/moto/enduro/tyre.html?utm_source=a%26b&custom%5Bx%5D=a%2Bb');
    httpCheck('canonical omits analytics', strpos($x['body'], '<link rel="canonical" href="' . $canonical . '">') !== false, true);
    httpCheck('analytics available to card', strpos($x['body'], 'utm_source') !== false, true);
    preg_match('~<script type="application/json" id="request">(.*?)</script>~s',$x['body'],$data);
    $parameters=json_decode($data[1]??'{}',true);
    httpCheck('analytics has exactly Request-clean encoding', $parameters['utm_source']??null, 'a&amp;b');
    httpCheck('nested functional parameter survives', $parameters['custom']['x']??null, 'a+b');
    $x=httpRequest($base,'/tyre.html?manufacturer_id=5&page=2&utm_source=a%26b&custom%5Bx%5D=a%2Bb');
    httpCheck('redirect preserves encoded nested parameters', $x['location']??null, $canonical.'?utm_source=a%26b&custom%5Bx%5D=a%2Bb');
    $x=httpRequest($base, '/moto/enduro/tyre.html?utm_source=a%26b&custom%5Bx%5D=a%2Bb','HEAD');
    httpCheck('HEAD current with nested params no body',$x['body'],'');
    foreach (['/moto/enduro/tyre.html','/tyre.html','/moto/tyre.html'] as $uri) {
        $x=httpRequest($base,$uri); $groups=json_decode($x['x-sql-groups']??'{}',true);
        httpCheck('one metadata per incoming request',$groups['schema_metadata']??null,1);
        httpCheck('one product address read per incoming request',$groups['product_address']??null,1);
        echo 'HTTP SQL '.$uri.' '.json_encode($groups)."\n";
    }
    echo "HTTP checks=$checks failed=$failed\n";
} finally { if (is_resource($process)) { proc_terminate($process); proc_close($process); } unlink($log); }
exit($failed ? 1 : 0);
