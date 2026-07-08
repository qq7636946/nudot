<?php
/**
 * /api —— 同網域 WordPress REST 反向代理。
 *
 * 前端只打 /api/...，本檔再轉發到 wordpress-1646034-6538315.cloudwaysapps.com。這樣瀏覽器端不需要跨網域請求，
 * 也能把 WordPress 回傳裡的後端網址改寫成前台可用的網址。
 */

const BACKEND_ORIGIN      = 'https://wordpress-1646034-6538315.cloudwaysapps.com';
const BACKEND_HTTP_ORIGIN = 'http://wordpress-1646034-6538315.cloudwaysapps.com';
const FRONTEND_ORIGIN     = 'https://nudot.com.tw';
const API_PREFIX          = '/api';

/* 新主機是公開的 Cloudways 網域，走正常 DNS 即可直連，留空即可。
   若之後把後端換成走 Cloudflare / CDN 的自訂網域、想直連 origin 繞過快取，
   再填主機真實 IP（新主機為 152.42.250.128），並確認該 IP 上有對應網域的 SSL 憑證。 */
const ORIGIN_IP = '';

const CACHE_TTL_JSON  = 300;
const CACHE_TTL_MEDIA = 86400;

const ALLOWED_CORS_ORIGINS = [
    'https://nudot.com.tw',
    'https://www.nudot.com.tw',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5500',
    'http://127.0.0.1:5500',
    'http://localhost:8080',
    'http://127.0.0.1:8080',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

send_base_headers();
handle_cors($method);

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET' && $method !== 'HEAD') {
    json_error(405, 'method_not_allowed', 'Only GET, HEAD and OPTIONS are supported.');
}

if (!function_exists('curl_init')) {
    json_error(500, 'curl_missing', 'PHP cURL extension is required for this API proxy.');
}

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$subPath = extract_proxy_path((string) $reqPath);
assert_allowed_path($subPath);

$qs       = $_SERVER['QUERY_STRING'] ?? '';
$isStatic = preg_match('#^/(wp-content|wp-includes)(/|$)#', $subPath) === 1;
$cacheable = ($method === 'GET' && !$isStatic);
$cacheFile = sys_get_temp_dir() . '/nudot_proxy_v2_' . sha1($subPath . '?' . $qs);

if ($cacheable && is_file($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TTL_JSON)) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['body'], $cached['content_type'], $cached['status'])) {
        send_proxy_response(
            (int) $cached['status'],
            (string) $cached['content_type'],
            (string) $cached['body'],
            false,
            $method,
            'HIT'
        );
    }
}

$target = BACKEND_ORIGIN . $subPath . ($qs !== '' ? '?' . $qs : '');
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_ENCODING       => '',
    CURLOPT_USERAGENT      => 'nudot-api-proxy/2.0',
    CURLOPT_HTTPHEADER     => ['Accept: */*'],
    CURLOPT_NOBODY         => ($method === 'HEAD'),
]);

if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}
if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}
if (ORIGIN_IP !== '') {
    curl_setopt($ch, CURLOPT_RESOLVE, [
        'wordpress-1646034-6538315.cloudwaysapps.com:443:' . ORIGIN_IP,
        'wordpress-1646034-6538315.cloudwaysapps.com:80:' . ORIGIN_IP,
    ]);
}

$body = curl_exec($ch);
if ($body === false) {
    $reason = curl_error($ch) ?: 'unknown cURL error';
    curl_close($ch);
    json_error(502, 'backend_unreachable', 'Backend API request failed.', ['reason' => $reason]);
}

$status = (int) (curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502);
$ctype  = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream');
curl_close($ch);

if (should_rewrite_body($ctype)) {
    $body = rewrite_backend_urls((string) $body);
}

if ($cacheable && $status >= 200 && $status < 300) {
    @file_put_contents($cacheFile, json_encode([
        'body'         => $body,
        'content_type' => $ctype,
        'status'       => $status,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

send_proxy_response($status, $ctype, (string) $body, $isStatic, $method, $isStatic ? 'BYPASS' : 'MISS');

function send_base_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Vary: Origin, Accept-Encoding');
}

function handle_cors(string $method): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    if (!in_array($origin, ALLOWED_CORS_ORIGINS, true)) {
        if ($method === 'OPTIONS') {
            json_error(403, 'cors_origin_denied', 'This origin is not allowed.');
        }
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

function extract_proxy_path(string $reqPath): string
{
    if ($reqPath === API_PREFIX || $reqPath === API_PREFIX . '/') {
        json_error(404, 'missing_proxy_path', 'Missing API path after /api.');
    }

    if (strpos($reqPath, API_PREFIX . '/') !== 0) {
        json_error(404, 'not_found', 'API route not found.');
    }

    $subPath = substr($reqPath, strlen(API_PREFIX));
    if ($subPath === false || $subPath === '' || preg_match('/[\x00-\x1F\x7F]/', $subPath)) {
        json_error(400, 'bad_path', 'Invalid API path.');
    }

    return $subPath;
}

function assert_allowed_path(string $subPath): void
{
    if (preg_match('#^/(wp-json|wp-content|wp-includes)(/|$)#', $subPath) === 1) {
        return;
    }

    json_error(404, 'not_found', 'Only WordPress REST and static asset paths are proxied.');
}

function should_rewrite_body(string $contentType): bool
{
    $contentType = strtolower($contentType);
    return strpos($contentType, 'json') !== false
        || strpos($contentType, 'text/') !== false
        || strpos($contentType, 'application/javascript') !== false
        || strpos($contentType, 'application/xml') !== false
        || strpos($contentType, 'image/svg+xml') !== false;
}

function rewrite_backend_urls(string $body): string
{
    $map = [
        BACKEND_ORIGIN . '/wp-json'       => FRONTEND_ORIGIN . API_PREFIX . '/wp-json',
        BACKEND_HTTP_ORIGIN . '/wp-json'  => FRONTEND_ORIGIN . API_PREFIX . '/wp-json',
        BACKEND_ORIGIN . '/wp-content'    => FRONTEND_ORIGIN . API_PREFIX . '/wp-content',
        BACKEND_HTTP_ORIGIN . '/wp-content' => FRONTEND_ORIGIN . API_PREFIX . '/wp-content',
        BACKEND_ORIGIN . '/wp-includes'   => FRONTEND_ORIGIN . API_PREFIX . '/wp-includes',
        BACKEND_HTTP_ORIGIN . '/wp-includes' => FRONTEND_ORIGIN . API_PREFIX . '/wp-includes',
        '//wordpress-1646034-6538315.cloudwaysapps.com/wp-json'     => FRONTEND_ORIGIN . API_PREFIX . '/wp-json',
        '//wordpress-1646034-6538315.cloudwaysapps.com/wp-content'  => FRONTEND_ORIGIN . API_PREFIX . '/wp-content',
        '//wordpress-1646034-6538315.cloudwaysapps.com/wp-includes' => FRONTEND_ORIGIN . API_PREFIX . '/wp-includes',
        BACKEND_ORIGIN                    => FRONTEND_ORIGIN,
        BACKEND_HTTP_ORIGIN               => FRONTEND_ORIGIN,
        '//wordpress-1646034-6538315.cloudwaysapps.com'             => FRONTEND_ORIGIN,
    ];

    foreach ($map as $from => $to) {
        $body = str_replace($from, $to, $body);
        $body = str_replace(str_replace('/', '\\/', $from), str_replace('/', '\\/', $to), $body);
    }

    return $body;
}

function send_proxy_response(int $status, string $contentType, string $body, bool $isStatic, string $method, string $cacheState): void
{
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=' . ($isStatic ? CACHE_TTL_MEDIA : CACHE_TTL_JSON));
    header('X-Nudot-Cache: ' . $cacheState);

    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

function json_error(int $status, string $code, string $message, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode([
        'ok'    => false,
        'error' => array_merge([
            'code'    => $code,
            'message' => $message,
        ], $extra),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
