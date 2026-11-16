<?php
// Own server per run, random port and handshake. Never accepts another process as a successful run.
error_reporting(E_ALL);
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
    echo "HTTP checks=$checks failed=$failed\n";
} finally { if (is_resource($process)) { proc_terminate($process); proc_close($process); } unlink($log); }
exit($failed ? 1 : 0);
