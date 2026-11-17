<?php
// Router for an isolated real HTTP server. The stock product controller and model execute unchanged;
// SQL and ancillary services are fixtures; the view exposes their actual canonical and breadcrumb data.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__ping__') { echo 'navigation ' . getenv('SEO_PRO_HTTP_RUN_ID'); return; }
ob_start();
require __DIR__ . '/test_seo_pro.php';
abstract class Model extends Controller {}
require $OC . 'catalog/model/catalog/product.php';
require $OC . 'catalog/controller/product/product.php';
require $OC . 'system/library/document.php';
class HttpProductModel extends ModelCatalogProduct {
    public $calls = 0;
    function getProduct($id) { $this->calls++; return parent::getProduct($id); }
}
class HttpDB extends DB {
    public $unavailable = false;
    function query($sql) {
        if (strpos($sql, 'SELECT DISTINCT *, pd.name AS name') === 0) {
            $this->log[] = $sql;
            if ($this->unavailable) return new Res([]);
            preg_match("~WHERE p.product_id = '(\d+)'~", $sql, $m);
            preg_match_all("~query->row\['([^']+)'\]~", file_get_contents(getenv('OC_DIR') . '/catalog/model/catalog/product.php'), $keys);
            $row = array_fill_keys($keys[1], '');
            $row = array_replace($row, ['product_id' => (int)$m[1], 'manufacturer_id' => 5, 'name' => 'Fixture tyre', 'meta_title' => 'Fixture tyre', 'rating' => 0, 'reviews' => 0, 'price' => 10, 'special' => null, 'quantity' => 1, 'minimum' => 1]);
            return new Res([$row]);
        }
        if (preg_match('~^(SELECT|UPDATE).*oc_(product_image|product_discount|product_option|product_attribute|product_related|product_recurring|product SET)~', $sql)) { $this->log[] = $sql; return new Res([]); }
        return parent::query($sql);
    }
}
class HttpResponse extends Response {
    public $body = '';
    function setOutput($body) { $this->body = $body; }
    function addHeader($h) { $this->headers[] = $h; }
}
class HttpService {
    public $registry;
    function __construct($r) { $this->registry = $r; }
    function get($key) { return $key; }
    function isLogged() { return false; }
    function calculate($price, ...$args) { return $price; }
    function format($price, ...$args) { return (string)$price; }
    function getCategory($id) { $c = $this->registry->get('db')->categories[$id] ?? null; return $c && $c['status'] ? ['name' => 'Category ' . $id] : false; }
    function getManufacturer($id) { return ['name' => 'Manufacturer ' . $id]; }
}
class HttpLoader extends Loader {
    function language($route) {}
    function model($route) { $this->calls[] = $route; }
    function controller($route) { return ''; }
    function view($route, $data) {
        $document = $this->registry->get('document'); $links = $document->getLinks();
        $html = '<h1>' . htmlspecialchars($data['heading_title'] ?? $route, ENT_QUOTES, 'UTF-8') . '</h1>';
        foreach ($links as $link) $html .= '<link rel="' . $link['rel'] . '" href="' . $link['href'] . '">';
        foreach ($data['breadcrumbs'] as $b) $html .= '<a href="' . $b['href'] . '">' . htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8') . '</a>';
        $html .= '<script type="application/json" id="request">' . json_encode($this->registry->get('request')->get, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        return $html;
    }
}
try {
    $uri = $_SERVER['REQUEST_URI']; $parts = parse_url($uri); parse_str($parts['query'] ?? '', $get);
    $path = ltrim($parts['path'] ?? '/', '/'); if ($path !== '' && $path !== 'index.php') $get['_route_'] = rawurldecode($path);
    [$r, $c] = boot(['get' => $get, 'server' => $_SERVER]);
    $db = new HttpDB(); foreach (get_object_vars($r->get('db')) as $k => $v) $db->$k = $v;
    $fixture = $_SERVER['HTTP_X_FIXTURE'] ?? '';
    if ($fixture === 'no-main') $db->main[1] = [];
    if ($fixture === 'no-keyword') unset($db->seo[0]);
    if ($fixture === 'no-category-keyword') unset($db->seo[2]);
    if ($fixture === 'old-category') $db->main[1] = [10 => 1];
    if ($fixture === 'unavailable') $db->unavailable = true;
    if ($fixture === 'hidden-ancestor') $db->categories[10]['status'] = 0;
    $r->set('db', $db); $r->set('load', new HttpLoader($r)); $response = new HttpResponse(); $r->set('response', $response);
    $model = new HttpProductModel($r); $r->set('model_catalog_product', $model);
    $r->set('document', new Document()); $r->get('session')->data['currency'] = 'USD';
    foreach (['language','customer','currency','tax','model_catalog_category','model_catalog_manufacturer'] as $k) $r->set($k, new HttpService($r));
    $entered = 0; $startup = 0;
    try {
        $c->index(); $startup = $model->calls;
        if (($r->get('request')->get['route'] ?? null) === 'product/product') { $entered++; (new ControllerProductProduct($r))->index(); }
        elseif (($r->get('request')->get['route'] ?? null) === 'error/not_found') { http_response_code(404); }
    } catch (Redirect $e) { $startup = $model->calls; header('Location: ' . $e->url, true, $e->status); }
    header('X-Startup-Product-Calls: ' . $startup); header('X-Card-Entered: ' . $entered);
    header('X-Notices: ' . $notices);
    $groups=[]; foreach (['product_address','category_address','incoming_path','schema_metadata','max','map','verify','fallback','foreign_core','product_model','all'] as $kind) $groups[$kind]=sql($r,$kind);
    header('X-SQL-Groups: ' . json_encode($groups));
    foreach ($response->headers as $h) header($h);
    if ($notices) http_response_code(500);
    echo $response->body;
} catch (Throwable $e) { http_response_code(500); echo 'FAIL ' . get_class($e) . ': ' . $e->getMessage(); }
ob_end_flush();
