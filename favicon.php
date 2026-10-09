<?php
/**
 * favicon.php — دریافت و کش آیکون (favicon) سایت‌ها برای نمایش در ویجت
 *
 * استفاده:  favicon.php?d=neshan.org
 * - آیکون سایت یک بار دریافت و ۳۰ روز در uploads/favicons ذخیره می‌شود.
 * - فقط دامنه‌های عمومی (نه آدرس‌های داخلی سرور) دریافت می‌شوند.
 * - اگر آیکونی پیدا نشد، یک آیکون پیش‌فرض (کره زمین) برگردانده می‌شود.
 */

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");

$default_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';

function fav_send_default($svg, $max_age = 86400)
{
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=' . (int)$max_age);
    echo $svg;
    exit;
}

// --- اعتبارسنجی دامنه ---
$d = strtolower(trim((string)($_GET['d'] ?? '')));
$d = preg_replace('/^www\./', '', $d);
if ($d === '' || strlen($d) > 253 || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d)) {
    fav_send_default($default_svg);
}

// --- کش ---
$cache_dir = rtrim(AICHAT_UPLOAD_DIR, '/') . '/favicons/';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
// فایل‌های کش فقط از طریق همین اسکریپت (با CSP sandbox) ارسال می‌شوند؛ دسترسی مستقیم بسته است
if (!is_file($cache_dir . '.htaccess')) @file_put_contents($cache_dir . '.htaccess', "Require all denied\n");
$base = $cache_dir . md5($d);
$ttl  = 30 * 86400;

foreach (['png', 'ico', 'jpg', 'gif', 'webp', 'svg', 'none'] as $ext) {
    $f = $base . '.' . $ext;
    if (is_file($f)) {
        $age = time() - filemtime($f);
        if ($ext === 'none') {
            if ($age < 86400) fav_send_default($default_svg);   // تا ۱ روز دوباره تلاش نکن
            @unlink($f);
            break;
        }
        if ($age < $ttl) {
            $types = ['png' => 'image/png', 'ico' => 'image/x-icon', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
            header('Content-Type: ' . $types[$ext]);
            header('Cache-Control: public, max-age=2592000');
            readfile($f);
            exit;
        }
        @unlink($f);
    }
}

/**
 * دریافت امن یک آدرس: فقط http/https، فقط IP عمومی، دنبال کردن دستی ریدایرکت
 */
function fav_fetch($url, $max_bytes = 400000, $hops = 3, $allow_partial = false)
{
    for ($i = 0; $i <= $hops; $i++) {
        $p = parse_url($url);
        if (empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
        $host = $p['host'];
        $port = isset($p['port']) ? (int)$p['port'] : (strtolower($p['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) return null;
        $ips = @gethostbynamel($host);
        if (!$ips) return null;
        $ip = $ips[0];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;

        $data = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_RESOLVE        => ["$host:$port:$ip"],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AIChatWidget-Favicon/1.0)',
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$data, $max_bytes) {
                $data .= $chunk;
                return strlen($data) > $max_bytes ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc   = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200 || $data === '') return null;
        if (strlen($data) > $max_bytes && !$allow_partial) return null;   // فایل تصویر خیلی بزرگ
        return ['body' => $data, 'ctype' => $ctype, 'url' => $url];
    }
    return null;
}

function fav_abs($href, $page_url)
{
    $href = trim(html_entity_decode($href, ENT_QUOTES));
    if ($href === '' || stripos($href, 'data:') === 0) return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($page_url);
    $root = $p['scheme'] . '://' . $p['host'];
    if (strpos($href, '//') === 0) return $p['scheme'] . ':' . $href;
    if ($href[0] === '/') return $root . $href;
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $root . $dir . $href;
}

function fav_image_ext($body)
{
    $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
    $mime = $finfo ? $finfo->buffer($body) : '';
    $map = [
        'image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
        'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
    ];
    if (isset($map[$mime])) return $map[$mime];
    // برخی فایل‌های ico با MIME عمومی شناسایی می‌شوند
    if (substr($body, 0, 4) === "\x00\x00\x01\x00") return 'ico';
    if (stripos(substr($body, 0, 300), '<svg') !== false) return 'svg';
    return '';
}

// --- ۱) صفحه اصلی سایت → <link rel="icon"> ---
$candidates = [];
foreach (["https://$d/", "https://www.$d/"] as $home) {
    $page = fav_fetch($home, 300000, 3, true);   // فقط بخش <head> صفحه کافی است
    if (!$page) continue;
    if (preg_match_all('/<link\b[^>]*>/i', $page['body'], $links)) {
        foreach ($links[0] as $tag) {
            if (!preg_match('/rel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rm)) continue;
            $rel = strtolower($rm[1]);
            if (strpos($rel, 'icon') === false) continue;
            if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $hm)) continue;
            $size = 16;
            if (preg_match('/sizes\s*=\s*["\']?(\d+)/i', $tag, $sm)) $size = (int)$sm[1];
            if (strpos($rel, 'apple-touch-icon') !== false) $size = max($size, 180);
            $candidates[] = ['url' => fav_abs($hm[1], $page['url']), 'size' => $size];
        }
    }
    break;
}
// ترجیح آیکون ۳۲ تا ۱۹۲ پیکسل
usort($candidates, function ($a, $b) {
    $sa = ($a['size'] >= 32 && $a['size'] <= 192) ? 1000 - abs(64 - $a['size']) : $a['size'];
    $sb = ($b['size'] >= 32 && $b['size'] <= 192) ? 1000 - abs(64 - $b['size']) : $b['size'];
    return $sb <=> $sa;
});
$candidates[] = ['url' => "https://$d/favicon.ico", 'size' => 16];
$candidates[] = ['url' => 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($d), 'size' => 64];

foreach (array_slice($candidates, 0, 5) as $c) {
    if ($c['url'] === '') continue;
    $img = fav_fetch($c['url'], 200000);
    if (!$img) continue;
    $ext = fav_image_ext($img['body']);
    if ($ext === '') continue;
    @file_put_contents($base . '.' . $ext, $img['body']);
    $types = ['png' => 'image/png', 'ico' => 'image/x-icon', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=2592000');
    echo $img['body'];
    exit;
}

@file_put_contents($base . '.none', '1');
fav_send_default($default_svg);
