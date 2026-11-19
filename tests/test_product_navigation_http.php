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
function standExpectedChain($chain, $uri, $build, $method) {
    if ($build !== 'candidate') return $chain;
    $chain = preg_replace_callback('~(?<=-> )\S+~', function ($match) { return standTarget($match[0]); }, $chain);
    if ($method !== 'POST' && $chain === '200' && urldecode(standTarget($uri)) !== urldecode($uri)) $chain = '301 -> ' . standTarget($uri) . ' 200';
    if (preg_match('~^301 -> (\S+) 200$~', $chain, $target) && urldecode($target[1]) === urldecode($uri)) $chain = '200';
    return $chain;
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
            $expectedChain = standExpectedChain($expectedChain, $uri, $build, $label);
            if ($build === 'candidate') $expectedCanonical = standTarget($expectedCanonical);
            $url = $base . $uri; $chain = []; $r = null;
            for ($n = 0; $n < 6; $n++) {
                $r = standHttpRequest($url, $n === 0 && $label === 'POST' ? 'POST' : 'GET', $n === 0 && $label === 'AJAX');
                $chain[] = (string)$r['status']; if ($r['location'] === '') break;
                if (strpos($r['location'], $base . '/') !== 0) throw new RuntimeException('unexpected redirect host');
                $chain[] = '-> ' . substr($r['location'], strlen($base)); $url = $r['location'];
            }
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
    mt_srand(20261115);$checks=0;$failed=0;$times=[];$redirectTimes=[];$currentTimes=[];
    for($i=0;$i<300;$i++) {
        $n=mt_rand(1,100500);$id=1000000+$n;
        $rows=$db->query("SELECT category_id, main_category FROM {$p}product_to_category WHERE product_id=$id ORDER BY main_category DESC,category_id")->rows;
        $mains=array_values(array_filter($rows,function($r){return (int)$r['main_category']===1;}));
        $candidates=$build==='candidate' ? (count($mains)===1?[(int)$mains[0]['category_id']]:[]) : ($mains?[(int)$mains[0]['category_id']]:array_map(function($r){return (int)$r['category_id'];},$rows));
        $expected=[];foreach($candidates as $category)$expected[]=$base.'/'.implode('/',array_map(function($id)use($kw){return rawurlencode($kw[$id]);},$paths[$category])).'/load-test-product-'.$n.'.html';
        if(!$expected)$expected=[$base.'/load-test-product-'.$n.'.html'];
        foreach(['/index.php?route=product/product&product_id='.$id,'/load-test-product-'.$n.'.html','/desktops/load-test-product-'.$n.'.html'] as $uri) {
            $r=standHttpRequest($base.$uri);$times[]=$r['ms'];if($r['status']===301)$redirectTimes[]=$r['ms'];elseif($r['status']===200)$currentTimes[]=$r['ms'];$checks++;
            $same=in_array($base.$uri,$expected,true);$ok=$same?($r['status']===200&&$r['location']===''):($r['status']===301&&in_array($r['location'],$expected,true));
            if(!$ok){$failed++;echo "FAIL sample $uri status={$r['status']} location={$r['location']}\n";}
        }
        if($i%10===0){$r=standHttpRequest($expected[0]);[$canon,$h1]=standDocument($r['body'],$base);$checks++;$h1Expected='Load test product '.$n.($n%10===0?' (H1)':'');if($r['status']!==200||!in_array($base.$canon,$expected,true)||$h1!==$h1Expected){$failed++;echo "FAIL sample card id=$id\n";}}
        if(($i+1)%50===0)echo 'sample progress products='.($i+1)."/300\n";
    }
    sort($times);echo "load sample build=$build products=300 checks=$checks failed=$failed all_statuses_TTFB_median_ms=".$times[intdiv(count($times),2)].' p95_ms='.$times[(int)(count($times)*.95)]."\n";
    foreach(['redirect_301'=>$redirectTimes,'current_200'=>$currentTimes]as$kind=>$values){sort($values);echo 'TTFB '.$kind.' count='.count($values).' median_ms='.($values?$values[intdiv(count($values),2)]:'not run').' p95_ms='.($values?$values[min(count($values)-1,(int)(count($values)*.95))]:'not run')."\n";}
    if($failed)throw new RuntimeException('load-product baseline differs');
}

function standFocusedProducts($db) {
    $base='https://test.mozap.ru';$checks=0;
    $assert=function($ok,$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException('focused HTTP: '.$label);};
    foreach([28=>'/htc-touch-hd.html',42=>'/component/monitor/test.html']as$id=>$path) {
        $row=$db->query('SELECT manufacturer_id FROM '.DB_PREFIX.'product WHERE product_id='.(int)$id)->row;
        $manufacturer=(int)$row['manufacturer_id'];$extras='utm_source=a%26b%3Dc&custom%5Bx%5D=a%2Bb';
        foreach([false,true]as$xhr)foreach(['GET','HEAD']as$method) {
            $r=standHttpRequest($base.$path.'?'.$extras,$method,$xhr);
            $assert($r['status']===200&&$r['location']==='',"current $id $method XHR=$xhr");
            if($method==='HEAD')$assert($r['body']==='','HEAD current has no body');
            else {[$canonical,$h1]=standDocument($r['body'],$base);$assert($canonical===$path,'canonical excludes analytics');$assert($h1!==''&&$h1!=='-','real H1 exists');}
            $old='/index.php?route=product/product&product_id='.$id.'&manufacturer_id='.$manufacturer.'&sort=p.price&order=ASC&page=2&limit=1&'.$extras;
            $r=standHttpRequest($base.$old,$method,$xhr);
            $assert($r['status']===301&&$r['location']===$base.$path.'?'.$extras,"one final redirect $id $method XHR=$xhr");
            if($method==='HEAD')$assert($r['body']==='','HEAD redirect has no body');
            foreach(['manufacturer_id=0','manufacturer_id=x','manufacturer_id='.($manufacturer+10000),'manufacturer_id%5B%5D='.$manufacturer]as$query) {
                $r=standHttpRequest($base.$path.'?'.$query,$method,$xhr);
                $assert($r['status']===404&&$r['location']==='',"invalid manufacturer $id $query $method XHR=$xhr");
                if($method==='HEAD')$assert($r['body']==='','HEAD error has no body');
            }
        }
        foreach(['POST','PUT','PATCH','DELETE']as$method) {
            $r=standHttpRequest($base.'/index.php?route=product/product&product_id='.$id.'&manufacturer_id='.$manufacturer,$method);
            $assert($r['status']!==301&&$r['location']==='',"$method has no new product redirect");
            echo "method product=$id $method status={$r['status']}\n";
        }
        $r=standHttpRequest($base.$path);$json=0;
        preg_match_all('~<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',$r['body'],$blocks);
        foreach($blocks[1]as$block){json_decode(trim($block),true,512,JSON_THROW_ON_ERROR);$json++;}
        echo "presentation product=$id canonical=$path JSON-LD_blocks=$json\n";
        if(!$json)echo "not run JSON-LD product=$id: theme returned no application/ld+json blocks\n";
        if(class_exists('DOMDocument')) {
            $dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8"?>'.$r['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);$links=[];
            foreach($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," breadcrumb ")]//a[@href]')as$a)$links[]=$a->getAttribute('href');
            $assert(count($links)>0,'theme breadcrumb anchors exist');
            foreach(array_unique($links)as$href)if(strpos($href,$base.'/')===0){$crumb=standHttpRequest($href);$assert($crumb['status']===200&&$crumb['location']==='','breadcrumb link opens directly');}
            echo 'breadcrumbs product='.$id.' '.json_encode(array_values(array_unique($links)),JSON_UNESCAPED_SLASHES)."\n";
        }else echo "not run breadcrumb DOM inspection: DOM extension unavailable\n";
    }
    $r=standHttpRequest($base.'/unknown-product-segment/htc-touch-hd.html');$assert($r['status']===404&&$r['location']==='','unknown segment rejected');
    $r=standHttpRequest($base.'/index.php?route=product/product&product_id%5B%5D=28');$assert($r['status']===404&&$r['location']==='','array product id rejected');
    foreach(['/desktops/?page=2','/apple.html?page=2']as$uri){$r=standHttpRequest($base.$uri);$assert($r['status']===200&&$r['location']==='','list pagination retained');}
    $r=standHttpRequest($base.'/desktops.html','GET',true);$assert($r['status']===200&&$r['location']==='','non-product XHR preserves previous no-redirect rule');
    $memo=[];
    foreach(['home'=>'/','category'=>'/desktops/?sort=p.price&order=ASC&page=2','manufacturer'=>'/apple.html?sort=p.price&order=ASC&page=2','search'=>'/index.php?route=product/search&search=apple&sort=p.price&order=ASC','card/related'=>'/component/monitor/test.html']as$origin=>$uri) {
        $r=standHttpRequest($base.$uri);$assert($r['status']===200&&$r['location']==='','list/card opens '.$origin);
        preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\']~i',$r['body'],$matches);$products=[];
        foreach(array_unique($matches[1])as$encoded) {
            $href=html_entity_decode($encoded,ENT_QUOTES,'UTF-8');if(strpos($href,$base.'/')!==0)continue;$parts=parse_url($href);$query=[];parse_str($parts['query']??'',$query);$product=($query['route']??null)==='product/product';
            if(!$product&&substr($parts['path']??'',-5)==='.html') {
                $slug=rawurldecode(basename(substr($parts['path'],0,-5)));
                if(!array_key_exists($slug,$memo))$memo[$slug]=$db->query("SELECT `query` FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND keyword='".$db->escape($slug)."' ORDER BY seo_url_id LIMIT 1")->row['query']??'';
                $product=preg_match('/^product_id=[1-9][0-9]*$/D',$memo[$slug])===1;
            }
            if(!$product)continue;$products[$href]=true;
            foreach(['manufacturer_id','sort','order','page','limit']as$key)$assert(!array_key_exists($key,$query),$origin.' product href excludes '.$key);
        }
        $assert(count($products)>0,'real product anchors observed '.$origin);
        echo "product hrefs origin=$origin unique=".count($products)." clean=yes\n";
    }
    echo "PASS focused stand checks=$checks\n";
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
// R1: a request with the scenario header X-Nav (see nav_scenario() in test_seo_pro.php); 'state' is the decoded X-Nav-State
function navRequest($base, $uri, $method, $nav, $xhr = false, $fixture = '') {
    $headers = ['X-Nav: ' . json_encode((object)$nav, JSON_UNESCAPED_SLASHES)]; if ($fixture !== '') $headers[] = "X-Fixture: $fixture"; if ($xhr) $headers[] = 'X-Requested-With: XMLHttpRequest';
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]];
    $body = file_get_contents($base . $uri, false, stream_context_create($options));
    $response = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : get_defined_vars()['http_response_header']; $out = ['status' => (int)explode(' ', $response[0])[1], 'body' => $body];
    foreach ($response as $header) if (strpos($header, ':') !== false) { [$key,$value] = explode(':', $header, 2); $out[strtolower($key)] = trim($value); }
    $out['state'] = json_decode($out['x-nav-state'] ?? 'null', true); $out['groups'] = json_decode($out['x-sql-groups'] ?? 'null', true);
    return $out;
}
// The next request of a client that follows Location: its path and query, and the scheme of Location
function navFollow($base, $location, $nav, $fixture = '') {
    $parts = parse_url((string)$location);
    // A relative or empty Location is a failure of the first request; it is reported, not resolved against this test server
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return ['status' => 'not followed: Location ' . json_encode($location) . ' is not absolute', 'body' => '', 'state' => null, 'groups' => null];
    $nav['https'] = $parts['scheme'] === 'https'; unset($nav['href']);
    return navRequest($base, ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''), 'GET', $nav, false, $fixture);
}
// hits, core_used, core_off of the controller after the request; 'missing' when the state was not reported
function navEntry($x) { $s = is_array($x['state']) ? $x['state'] : []; $out = []; foreach (['hits', 'core_used', 'core_off'] as $k) $out[] = array_key_exists($k, $s) ? $s[$k] : 'missing'; return $out; }
function navBuilds($x, $field) { return array_map(function ($b) use ($field) { return $b[$field] ?? 'missing'; }, $x['state']['builds'] ?? []); }
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
    httpCheck('stand chain preserves status separators', standExpectedChain('301 -> /desktops/htc-touch-hd.html?blog_path=1 200', '/blog-category-1/htc-touch-hd', 'candidate', 'GET'), '301 -> /htc-touch-hd.html?blog_path=1 200');
    httpCheck('stand old long address gains planned redirect', standExpectedChain('200', '/desktops/htc-touch-hd.html?blogarticle_id=1', 'candidate', 'GET'), '301 -> /htc-touch-hd.html?blogarticle_id=1 200');
    httpCheck('stand already short address collapses old redirect', standExpectedChain('301 -> /desktops/htc-touch-hd.html 200', '/htc-touch-hd.html', 'candidate', 'GET'), '200');
    httpCheck('stand encoded spaces stay equivalent', standExpectedChain('200', '/htc-touch-hd.html?x=a%20b', 'candidate', 'GET'), '200');
    httpCheck('stand baseline chain unchanged', standExpectedChain('301 -> /desktops/htc-touch-hd.html?blog_path=1 200', '/blog-category-1/htc-touch-hd', 'baseline', 'GET'), '301 -> /desktops/htc-touch-hd.html?blog_path=1 200');
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

    // R1: the redirect target is the ordinary href of the registered Url chain. Each first request is an old valid address,
    // so availability is checked once before the 301; the Location is then requested as the next client request would.
    $entryDefault = [null, false, false];
    $r1 = [
        'empty config_ssl under HTTPS' => [['https' => true, 'config' => ['config_ssl' => '']], '', 'http://shop.test/'],
        'ordinary HTTPS' => [['https' => true], '', 'https://shop.test/'],
        'deployment subdirectory /shop/' => [['config' => ['config_url' => 'http://shop.test/shop/', 'config_ssl' => 'https://shop.test/shop/'], 'strip' => 'shop/'], '/shop', 'http://shop.test/shop/'],
        'SEO Pro then deterministic prefix rewriter (simulated incoming routing)' => [['rewriters' => ['prefix'], 'strip' => 'en/'], '/en', 'http://shop.test/en/'],
    ];
    foreach ($r1 as $label => [$nav, $root, $target]) {
        $nav['href'] = 'product_id=1&utm_source=a%26b';
        $expected = $target . 'moto/enduro/tyre.html?utm_source=a%26b';
        foreach ([false, true] as $xhr) foreach (['GET', 'HEAD'] as $method) {
            $tag = "R1 $label $method XHR=" . (int)$xhr;
            $x = navRequest($base, $root . '/tyre.html?manufacturer_id=5&page=2&utm_source=a%26b', $method, $nav, $xhr);
            httpCheck("$tag: one 301", $x['status'], 301);
            httpCheck("$tag: absolute final Location", $x['location'] ?? null, $expected);
            httpCheck("$tag: Location is the ordinary href of the registered Url chain", $x['location'] ?? null, $x['x-nav-href'] ?? null);
            httpCheck("$tag: no doubled subdirectory", strpos($x['location'] ?? '', '/shop/shop/'), false);
            httpCheck("$tag: one startup getProduct before the redirect, no card", [$x['x-startup-product-calls'] ?? null, $x['x-card-entered'] ?? null, $x['x-notices'] ?? null], ['1', '0', '0']);
            httpCheck("$tag: one target build through the registered Url chain", count($x['state']['builds'] ?? []), 1);
            httpCheck("$tag: the build saw the registered Url and the Request unchanged", [navBuilds($x, 'url_same'), navBuilds($x, 'get_final')], [[true], [true]]);
            httpCheck("$tag: transient fields back at their entry state", navEntry($x), $entryDefault);
            httpCheck("$tag: Registry Url is the registered one", $x['state']['url_registered'] ?? null, true);
            if ($method === 'HEAD') httpCheck("$tag: HEAD body", $x['body'], '');
            $y = navFollow($base, $x['location'] ?? '', $nav);
            httpCheck("$tag: Location opens without a second redirect", [$y['status'], $y['location'] ?? null], [200, null]);
            httpCheck("$tag: Location calls no startup getProduct, the card runs", [$y['x-startup-product-calls'] ?? null, $y['x-card-entered'] ?? null, $y['x-notices'] ?? null], ['0', '1', '0']);
        }
        $x = navRequest($base, $root . '/moto/enduro/tyre.html?utm_source=a%26b', 'GET', $nav);
        httpCheck("R1 $label: current address under the request scheme, no redirect", [$x['status'], $x['location'] ?? null, $x['x-startup-product-calls'] ?? null], [200, null, '0']);
        foreach (['POST', 'PUT'] as $method) {
            $x = navRequest($base, $root . '/tyre.html?manufacturer_id=5&page=2', $method, $nav);
            httpCheck("R1 $label $method: existing non-GET contract, no redirect", [isset($x['location']), $x['x-startup-product-calls'] ?? null], [false, '0']);
        }
        foreach (['unavailable product' => [$root . '/tyre.html?manufacturer_id=5', 'unavailable'], 'wrong manufacturer' => [$root . '/tyre.html?manufacturer_id=6', ''],
            'array manufacturer' => [$root . '/tyre.html?manufacturer_id%5B%5D=5', ''], 'bad incoming path' => [$root . '/index.php?route=product/product&path=10_99&product_id=1', '']] as $case => [$uri, $fixture]) {
            foreach (['GET', 'HEAD'] as $method) {
                $x = navRequest($base, $uri, $method, $nav, false, $fixture);
                httpCheck("R1 $label $case $method: not found before any redirect", [$x['status'], isset($x['location'])], [404, false]);
            }
        }
    }

    // R1: one decode of the Request::clean() layer. Literal entity text, escaped names, nested and analytics values survive exactly once
    $query = 'q=%26amp%3B&a%26b=1&f%5Bx%26y%5D=1&utm_source=a%26b&custom%5Bx%5D=a%2Bb';
    $nav = ['href' => 'product_id=1&' . $query];
    foreach (['GET', 'HEAD'] as $method) {
        $x = navRequest($base, '/index.php?route=product/product&product_id=1&manufacturer_id=5&sort=p.price&' . $query, $method, $nav);
        httpCheck("R1 decode $method: one 301 with the values decoded once", [$x['status'], $x['location'] ?? null], [301, 'http://shop.test/moto/enduro/tyre.html?' . $query]);
        httpCheck("R1 decode $method: Location is the ordinary href", $x['location'] ?? null, $x['x-nav-href'] ?? null);
        httpCheck("R1 decode $method: no route/product_id serialized", [substr_count($x['location'] ?? '', 'route='), substr_count($x['location'] ?? '', 'product_id=')], [0, 0]);
    }
    $y = navFollow($base, $x['location'] ?? '', $nav);
    httpCheck('R1 decode: Location opens without a second redirect', [$y['status'], $y['location'] ?? null, $y['x-startup-product-calls'] ?? null], [200, null, '0']);
    preg_match('~<script type="application/json" id="request">(.*?)</script>~s', $y['body'], $data); $parameters = json_decode($data[1] ?? '{}', true);
    httpCheck('R1 decode: the card gets the parameters with exactly one Request::clean() layer',
        [$parameters['q'] ?? null, $parameters['a&amp;b'] ?? null, $parameters['f'] ?? null, $parameters['utm_source'] ?? null, $parameters['custom'] ?? null, isset($parameters['manufacturer_id']), isset($parameters['sort'])],
        ['&amp;amp;', '1', ['x&amp;y' => '1'], 'a&amp;b', ['x' => 'a+b'], false, false]);
    $nav = ['href' => 'product_id=1&utm_source=x'];
    $x = navRequest($base, '/index.php?route=product/product&product_id=1&manufacturer_id=5&utm_source=x', 'GET', $nav, false, 'no-keyword');
    httpCheck('R1 native fallback: route/path/product_id once', [$x['status'], $x['location'] ?? null], [301, 'http://shop.test/index.php?route=product/product&path=10_11&product_id=1&utm_source=x']);
    httpCheck('R1 native fallback: Location is the ordinary href', $x['location'] ?? null, $x['x-nav-href'] ?? null);
    httpCheck('R1 native fallback: no duplicate serialization', [substr_count($x['location'] ?? '', 'route='), substr_count($x['location'] ?? '', 'product_id=')], [1, 1]);
    $y = navFollow($base, $x['location'] ?? '', $nav, 'no-keyword');
    httpCheck('R1 native fallback: Location opens without a second redirect', [$y['status'], $y['location'] ?? null, $y['x-startup-product-calls'] ?? null], [200, null, '0']);

    // R1: transient state. Entry values are set on the controller before index(); a route-only address keeps them up to the handler
    $old = '/index.php?route=product/product&product_id=1&manufacturer_id=5';
    $sentinel = ['hits' => ['entry-hit'], 'core_used' => true, 'core_off' => false];
    foreach (['GET', 'HEAD'] as $method) {
        $x = navRequest($base, $old, $method, ['rewriters' => ['throw'], 'entry' => $sentinel]);
        httpCheck("R1 throwing registered rewriter $method: the exception stays visible", $x['status'], 500);
        if ($method === 'GET') httpCheck('R1 throwing registered rewriter: its message', strpos($x['body'], 'RuntimeException: nav test rewriter failure') !== false, true);
        httpCheck("R1 throwing registered rewriter $method: hits/core_used/core_off restored to entry", navEntry($x), [['entry-hit'], true, false]);
        httpCheck("R1 throwing registered rewriter $method: Request GET and Registry Url untouched", [$x['state']['get_initial'] ?? null, $x['state']['url_registered'] ?? null, navBuilds($x, 'get_initial'), navBuilds($x, 'url_same')], [true, true, [true], [true]]);
    }
    $x = navRequest($base, $old, 'GET', ['rewriters' => ['throw'], 'entry' => ['core_off' => true]]);
    httpCheck('R1 prior core_off=true, throwing rewriter: entry value kept after the error', [$x['status'], navEntry($x)], [500, [null, false, true]]);
    $x = navRequest($base, $old, 'GET', ['rewriters' => ['core-touch'], 'core' => 'throw', 'entry' => ['hits' => ['entry-hit'], 'core_used' => false, 'core_off' => false]]);
    httpCheck('R1 throwing core resolution: the exception stays visible', [$x['status'], strpos($x['body'], 'RuntimeException: nav test core failure') !== false], [500, true]);
    httpCheck('R1 throwing core resolution: hits/core_used/core_off restored to entry', navEntry($x), [['entry-hit'], false, false]);
    httpCheck('R1 throwing core resolution: Request GET and Registry Url restored', [$x['state']['get_initial'] ?? null, $x['state']['url_registered'] ?? null, count($x['state']['builds'] ?? [])], [true, true, 1]);
    $x = navRequest($base, '/note-5', 'GET', ['foreign' => true, 'core' => 'throw']);
    httpCheck('incoming throwing core resolution: visible, Request GET and Registry Url restored as before', [$x['status'], $x['state']['get'] ?? null, $x['state']['url_registered'] ?? null, navEntry($x)], [500, [], true, $entryDefault]);
    $x = navRequest($base, $old, 'GET', ['entry' => $sentinel, 'href' => 'product_id=1']);
    httpCheck('R1 non-default entry state: one build, no spurious freshness or core rebuild', [$x['status'], count($x['state']['builds'] ?? []), $x['groups']['map'] ?? null, $x['groups']['verify'] ?? null], [301, 1, 1, 1]);
    httpCheck('R1 non-default entry state: restored after the redirect', navEntry($x), [['entry-hit'], true, false]);
    httpCheck('R1 non-default entry state: Location is the ordinary href', [$x['location'] ?? null, $x['x-nav-href'] ?? null], ['http://shop.test/moto/enduro/tyre.html', 'http://shop.test/moto/enduro/tyre.html']);

    // R1: bounded rebuilds. A stale cached map triggers at most one freshness rebuild; a core-built target that opens another
    // route triggers at most one core-disabled rebuild; the target is compared after each build, and no loop follows
    $stale = ['moto' => 'moto-old'];
    foreach ([
        'core-disabled rebuild' => [['rewriters' => ['core-touch'], 'core' => 'other'], [false, true], null, $entryDefault],
        'core-disabled rebuild, prior core_off=true' => [['rewriters' => ['core-touch'], 'core' => 'other', 'entry' => ['core_off' => true]], [true], null, [null, false, true]],
        'freshness rebuild' => [['stale' => $stale], [false, false], 1, $entryDefault],
        'freshness rebuild, prior core_off=true' => [['stale' => $stale, 'entry' => ['core_off' => true]], [true, true], 1, [null, false, true]],
        'freshness then core-disabled rebuild' => [['stale' => $stale, 'rewriters' => ['core-touch'], 'core' => 'other'], [false, false, true], 1, $entryDefault],
    ] as $case => [$nav, $coreOff, $maps, $after]) {
        $nav['href'] = 'product_id=1';
        $x = navRequest($base, $old, 'GET', $nav);
        httpCheck("R1 $case: one 301 to the ordinary href", [$x['status'], $x['location'] ?? null, $x['x-nav-href'] ?? null], [301, 'http://shop.test/moto/enduro/tyre.html', 'http://shop.test/moto/enduro/tyre.html']);
        httpCheck("R1 $case: builds and core_off during each build", navBuilds($x, 'core_off'), $coreOff);
        httpCheck("R1 $case: transient fields and core_off back at entry", navEntry($x), $after);
        httpCheck("R1 $case: one startup getProduct", $x['x-startup-product-calls'] ?? null, '1');
        if ($maps !== null) httpCheck("R1 $case: one map rebuild, product address memoized", [$x['groups']['map'] ?? null, $x['groups']['verify'] ?? null, $x['groups']['product_address'] ?? null], [$maps, 1, 1]);
        $y = navFollow($base, $x['location'] ?? '', $nav);
        httpCheck("R1 $case: Location opens without a second redirect", [$y['status'], $y['location'] ?? null, $y['x-startup-product-calls'] ?? null], [200, null, '0']);
    }
    $x = navRequest($base, $old, 'GET', ['stale' => $stale, 'rewriters' => ['core-touch'], 'core' => 'throw', 'entry' => ['hits' => ['entry-hit'], 'core_used' => false, 'core_off' => false]]);
    httpCheck('R1 freshness rebuild then throwing core resolution: visible, two builds, entry restored', [$x['status'], count($x['state']['builds'] ?? []), navEntry($x), $x['state']['get_initial'] ?? null, $x['state']['url_registered'] ?? null], [500, 2, [['entry-hit'], false, false], true, true]);
    echo "HTTP checks=$checks failed=$failed\n";
} finally { if (is_resource($process)) { proc_terminate($process); proc_close($process); } unlink($log); }
exit($failed ? 1 : 0);
