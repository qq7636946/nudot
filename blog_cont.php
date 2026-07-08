<?php
/**
 * blog_cont.php —— headless WordPress 文章頁的伺服器端 SEO 殼
 *
 * 作用：讀取 blog_cont.html 當模板，依 ?id= 去 WP REST API 抓 Yoast 的
 *      yoast_head_json + 後台精選圖片，在「輸出 HTML 之前」就把 <title> 與
 *      SEO:AUTO 區塊換成該篇文章的真實 meta。這樣 Google 第一波抓取、以及
 *      FB/LINE/X 等不執行 JS 的社群爬蟲，都能直接拿到正確標題、描述與 OG 圖。
 *
 * .htaccess 的「無副檔名先找 .php」規則會讓 /blog_cont?id=123 自動走這支，
 * 不需要改任何內部連結；前端的 applyYoastSeo() JS 仍保留當 fallback。
 */

const TEMPLATE     = __DIR__ . '/blog_cont.html';
const API_BASE     = 'https://wordpress-1646034-6538315.cloudwaysapps.com/wp-json/wp/v2';
const SITE_ORIGIN  = 'https://nudot.com.tw';
const DEFAULT_OG   = SITE_ORIGIN . '/images/og.jpg';
const CACHE_TTL    = 600; // 秒；API 回應快取時間，降低 TTFB
const API_PREFIX   = '/api';

const BACKEND_ORIGIN      = 'https://wordpress-1646034-6538315.cloudwaysapps.com';
const BACKEND_HTTP_ORIGIN = 'http://wordpress-1646034-6538315.cloudwaysapps.com';

/* 新主機是公開的 Cloudways 網域，走正常 DNS 即可直連，留空即可。
   若之後把後端換成走 Cloudflare / CDN 的自訂網域、想直連 origin 繞過快取，
   再填主機真實 IP（新主機為 152.42.250.128），並與 api/index.php 的 ORIGIN_IP 保持一致。 */
const ORIGIN_IP    = '';

$html = @file_get_contents(TEMPLATE);
if ($html === false) {
    http_response_code(500);
    exit('template missing');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// 沒有 id 或抓不到資料 → 原樣輸出（保留 HTML 內的預設 fallback meta）
if ($id > 0 && ($post = fetch_post($id)) !== null && !empty($post['yoast_head_json'])) {
    $canonical = SITE_ORIGIN . '/blog_cont?id=' . $id; // 乾淨網址，避免被 .htaccess 301
    $html = inject_seo($html, $post, $canonical);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;


/**
 * 向 WP REST API 取單篇文章（含 yoast_head_json 與精選圖片），帶檔案快取。
 */
function fetch_post(int $id): ?array
{
    $cacheFile = sys_get_temp_dir() . '/nudot_seo_' . $id . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TTL)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $url  = API_BASE . '/posts/' . $id . '?_embed=wp:featuredmedia&_fields=yoast_head_json,_embedded';
    $body = http_get($url);
    if ($body === null) return null;

    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    @file_put_contents(
        $cacheFile,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $data;
}

/** 簡單的 HTTP GET，優先用 cURL，帶短逾時。失敗回傳 null。 */
function http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'nudot-seo-shell/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        if (ORIGIN_IP !== '') {
            curl_setopt($ch, CURLOPT_RESOLVE, [
                'wordpress-1646034-6538315.cloudwaysapps.com:443:' . ORIGIN_IP,
                'wordpress-1646034-6538315.cloudwaysapps.com:80:' . ORIGIN_IP,
            ]);
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $code !== 200) ? null : $body;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: nudot-seo-shell/1.0\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/**
 * 從後台精選圖片挑最適合 OG 的尺寸：寬度 ≥1200 中最小的；都 <1200 就挑最大的。
 * 沒設精選圖回傳 null（交給呼叫端退回 Yoast 社群圖 / 預設圖）。
 */
function pick_featured_image(array $post): ?array
{
    $m = $post['_embedded']['wp:featuredmedia'][0] ?? null;
    if (!$m || !empty($m['code'])) return null; // code 代表媒體讀取錯誤

    $det   = $m['media_details'] ?? [];
    $sizes = $det['sizes'] ?? [];
    $best  = null;
    foreach ($sizes as $s) {
        if (empty($s['source_url']) || empty($s['width'])) continue;
        if ($best === null) { $best = $s; continue; }
        $bw = $best['width'];
        $sw = $s['width'];
        $bestOk = $bw >= 1200;
        $sOk    = $sw >= 1200;
        if ($sOk && (!$bestOk || $sw < $bw)) {
            $best = $s;                          // s 合格且更接近 1200
        } elseif (!$sOk && !$bestOk && $sw > $bw) {
            $best = $s;                          // 都不到 1200，挑更大的
        }
    }
    if ($best) {
        return ['url' => $best['source_url'], 'width' => $best['width'], 'height' => $best['height']];
    }
    if (!empty($m['source_url'])) {
        return ['url' => $m['source_url'], 'width' => $det['width'] ?? null, 'height' => $det['height'] ?? null];
    }
    return null;
}

/** 把 <title> 與 <!-- SEO:AUTO:START/END --> 之間的內容換成 Yoast meta。 */
function inject_seo(string $html, array $post, string $canonical): string
{
    $y = $post['yoast_head_json'];

    // ── <title> ──
    if (!empty($y['title'])) {
        $html = preg_replace('/<title>.*?<\/title>/su', '<title>' . h($y['title']) . '</title>', $html, 1);
    }

    // ── 組 SEO 區塊 ──
    $tags = [];
    $meta_name = function ($name, $content) use (&$tags) {
        if ($content !== null && $content !== '') {
            $tags[] = '  <meta name="' . h($name) . '" content="' . h($content) . '">';
        }
    };
    $meta_prop = function ($prop, $content) use (&$tags) {
        if ($content !== null && $content !== '') {
            $tags[] = '  <meta property="' . h($prop) . '" content="' . h($content) . '">';
        }
    };

    $meta_name('description', $y['description'] ?? null);
    // Robots：前台（nudot.com.tw）一律 index, follow，不沿用 Yoast 的 robots。
    // 即使 WP 後台維持 noindex（讓 wordpress-1646034-6538315.cloudwaysapps.com 後端不被搜尋到），
    // 前台仍可被 Google 正常索引。保留 max-snippet / max-image-preview 等進階指示。
    $meta_name('robots', 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1');

    $tags[] = '  <link rel="canonical" href="' . h($canonical) . '">';

    // Open Graph（canonical/og:url 一律指向自己的網域）
    $meta_prop('og:locale', $y['og_locale'] ?? null);
    $meta_prop('og:type', $y['og_type'] ?? 'article');
    $meta_prop('og:title', $y['og_title'] ?? $y['title'] ?? null);
    $meta_prop('og:description', $y['og_description'] ?? $y['description'] ?? null);
    $meta_prop('og:url', $canonical);
    $meta_prop('og:site_name', '核點 Nudot Studio'); // 固定前台品牌，不用 WP 後台網站標題（目前是預設 My Blog）
    $meta_prop('article:published_time', $y['article_published_time'] ?? null);
    $meta_prop('article:modified_time', $y['article_modified_time'] ?? null);

    // og:image —— 優先後台精選圖片 → Yoast 社群圖 → 網站預設圖
    $img = pick_featured_image($post);
    if (!$img) {
        $yImg = (!empty($y['og_image']) && is_array($y['og_image'])) ? $y['og_image'][0] : null;
        if ($yImg && !empty($yImg['url'])) {
            $img = ['url' => $yImg['url'], 'width' => $yImg['width'] ?? null, 'height' => $yImg['height'] ?? null];
        }
    }
    if (!$img) {
        $img = ['url' => DEFAULT_OG, 'width' => 1200, 'height' => 630];
    }
    $img['url'] = proxify($img['url']); // 圖片改走 /api，隱藏後端網域
    $meta_prop('og:image', $img['url']);
    if (!empty($img['width']))  $meta_prop('og:image:width', $img['width']);
    if (!empty($img['height'])) $meta_prop('og:image:height', $img['height']);

    // Twitter
    $meta_name('twitter:card', $y['twitter_card'] ?? 'summary_large_image');
    $meta_name('twitter:title', $y['twitter_title'] ?? $y['og_title'] ?? null);
    $meta_name('twitter:description', $y['twitter_description'] ?? $y['og_description'] ?? null);
    $meta_name('twitter:image', $img['url']); // 與 og:image 同步用精選圖片

    // JSON-LD：精簡自製 BlogPosting，只輸出必要欄位且全部指向自己網域。
    // 不用 Yoast 整包 schema graph，避免暴露後端網域、作者頁、WordPress 結構等資訊。
    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        'headline'         => $y['og_title'] ?? $y['title'] ?? '',
        'url'              => $canonical,
    ];
    if (!empty($y['description']))            $schema['description']   = $y['description'];
    if (!empty($img['url']))                  $schema['image']         = $img['url'];
    if (!empty($y['article_published_time'])) $schema['datePublished'] = $y['article_published_time'];
    if (!empty($y['article_modified_time']))  $schema['dateModified']  = $y['article_modified_time'];
    $schema['author'] = ['@type' => 'Organization', 'name' => '核點 Nudot Studio', 'url' => SITE_ORIGIN];
    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        $tags[] = '  <script type="application/ld+json" id="site-schema">' . $json . '</script>';
    }

    $block = implode("\n", $tags);

    return preg_replace(
        '/<!-- SEO:AUTO:START.*?-->.*?<!-- SEO:AUTO:END -->/su',
        "<!-- SEO:AUTO (server-rendered by blog_cont.php) -->\n" . $block,
        $html,
        1
    );
}

/** HTML 屬性/文字轉義。 */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** 把後端網域改寫成 /api 代理路徑，隱藏 wordpress-1646034-6538315.cloudwaysapps.com。 */
function proxify($s): string
{
    $url = (string) $s;
    $map = [
        BACKEND_ORIGIN . '/wp-json'        => SITE_ORIGIN . API_PREFIX . '/wp-json',
        BACKEND_HTTP_ORIGIN . '/wp-json'   => SITE_ORIGIN . API_PREFIX . '/wp-json',
        BACKEND_ORIGIN . '/wp-content'     => SITE_ORIGIN . API_PREFIX . '/wp-content',
        BACKEND_HTTP_ORIGIN . '/wp-content' => SITE_ORIGIN . API_PREFIX . '/wp-content',
        BACKEND_ORIGIN . '/wp-includes'    => SITE_ORIGIN . API_PREFIX . '/wp-includes',
        BACKEND_HTTP_ORIGIN . '/wp-includes' => SITE_ORIGIN . API_PREFIX . '/wp-includes',
        '//wordpress-1646034-6538315.cloudwaysapps.com/wp-json'      => SITE_ORIGIN . API_PREFIX . '/wp-json',
        '//wordpress-1646034-6538315.cloudwaysapps.com/wp-content'   => SITE_ORIGIN . API_PREFIX . '/wp-content',
        '//wordpress-1646034-6538315.cloudwaysapps.com/wp-includes'  => SITE_ORIGIN . API_PREFIX . '/wp-includes',
        BACKEND_ORIGIN                     => SITE_ORIGIN,
        BACKEND_HTTP_ORIGIN                => SITE_ORIGIN,
        '//wordpress-1646034-6538315.cloudwaysapps.com'              => SITE_ORIGIN,
    ];

    foreach ($map as $from => $to) {
        $url = str_replace($from, $to, $url);
    }

    return $url;
}
