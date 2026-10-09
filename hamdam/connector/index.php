<?php
/**
 * فایل رابط چت‌بات
 * این پوشه را روی هاست سایت خود آپلود کنید (مثلاً: yoursite.com/chat/)
 * - صفحه کامل چت:            https://yoursite.com/chat/
 * - دکمه شناور در سایت:      <script src="https://yoursite.com/chat/?a=embed" defer></script>
 *
 * سازگار با PHP 7.2 به بالا — نیازمند افزونه curl (یا allow_url_fopen)
 */

error_reporting(0);
// حالت دامنه اختصاصی: HD_LOCAL از قبل تعریف شده و نیازی به config.php نیست
if (!defined('HD_LOCAL')) {
    if (!is_file(__DIR__ . '/config.php')) { http_response_code(500); exit('Configuration file (config.php) not found.'); }
    require __DIR__ . '/config.php';
}
if (!defined('HD_SERVER') || !defined('HD_KEY')) { http_response_code(500); exit('Invalid configuration.'); }

define('HDC_DOWN', 'با توجه به قطعی شبکه، فعلاً امکان پاسخگویی نیست.');
define('HDC_COOKIE', 'hdc_t');

// ---------------------------------------------------------------------
// توابع کمکی
// ---------------------------------------------------------------------
function hdc_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

function hdc_base_url()
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return (hdc_is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/';
}

function hdc_sub($s, $n)
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n * 2);
}

function hdc_client_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** ارسال درخواست به سرور (فقط سرور-به-سرور) */
function hdc_call($payload, $timeout = 90)
{
    $payload['client_ip'] = hdc_client_ip();
    $payload['client_ua'] = hdc_sub((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 250);   // گزارش دستگاه ورود مخاطب
    if (!defined('HD_LOCAL')) $payload['cv'] = hdc_self_hash();
    if (defined('HD_LOCAL')) {   // اجرای مستقیم روی همان سرور (دامنه اختصاصی)
        try {
            $r = hd_api_dispatch($GLOBALS['hdc_local_pdo'], $GLOBALS['hdc_local_bot'], $payload);
            // پس‌پردازش اختیاری میزبان محلی (مثلاً چت صفحه اصلی سایت: انتقال گفتگوی مهمان پس از ورود)
            if (isset($GLOBALS['hdc_local_after']) && is_callable($GLOBALS['hdc_local_after']) && is_array($r)) $r = ($GLOBALS['hdc_local_after'])($payload, $r);
            return is_array($r) ? $r : ['ok' => false, 'error' => HDC_DOWN];
        } catch (\Throwable $e) {
            error_log('[HD] local: ' . $e->getMessage());
            return ['ok' => false, 'error' => HDC_DOWN];
        }
    }
    $body = json_encode($payload);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-HD-Key: ' . HD_KEY];
    $res = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(HD_SERVER);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $res = @file_get_contents(HD_SERVER, false, $ctx);
    }
    $data = $res ? json_decode($res, true) : null;
    return is_array($data) ? $data : ['ok' => false, 'error' => HDC_DOWN];
}

function hdc_cache_file($name)
{
    $dir = defined('HDC_CACHE_DIR') ? HDC_CACHE_DIR : __DIR__ . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
    return rtrim($dir, '/\\') . '/hdc_' . substr(md5(HD_KEY . $name), 0, 16);
}

/** اثر انگشت همین فایل (برای تشخیص نسخه) */
function hdc_self_hash()
{
    static $h = null;
    if ($h === null) $h = (string)@hash_file('sha256', __FILE__);
    return $h;
}

/**
 * به‌روزرسانی خودکار همین فایل از سرور (هر ۶ ساعت یک بار بررسی می‌شود).
 * فقط از طریق اتصال امن (HTTPS با گواهی معتبر) و با امضای کلید محرمانه ربات انجام می‌شود.
 * اگر هاست اجازه نوشتن روی فایل را ندهد، کاری انجام نمی‌شود.
 */
function hdc_self_update($force = false)
{
    if (defined('HD_LOCAL')) return 'local';
    $flag = hdc_cache_file('update_check');
    if (!$force && is_file($flag) && filemtime($flag) > time() - 6 * 3600) return 'recent';
    @touch($flag);
    if (!is_writable(__FILE__) || !is_writable(__DIR__)) return 'not writable';
    $secure = stripos(HD_SERVER, 'https://') === 0;
    if (!$secure && !defined('HDC_UPDATE_INSECURE')) return 'insecure';
    $get = function ($action) use ($secure) {
        $body = json_encode(['action' => $action]);
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-HD-Key: ' . HD_KEY];
        if (function_exists('curl_init')) {
            $ch = curl_init(HD_SERVER);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $res = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 30, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $res = @file_get_contents(HD_SERVER, false, $ctx);
        }
        $d = $res ? json_decode($res, true) : null;
        return is_array($d) ? $d : null;
    };
    $v = $get('connector_version');
    if (empty($v['ok']) || !preg_match('/^[a-f0-9]{64}$/', (string)($v['hash'] ?? ''))) return 'no version';
    if (hash_equals((string)$v['hash'], hdc_self_hash())) return 'up to date';
    $c = $get('connector_code');
    if (empty($c['ok']) || empty($c['code'])) return 'no code';
    $code = base64_decode((string)$c['code'], true);
    if ($code === false || strlen($code) < 2000) return 'bad code';
    if (!hash_equals((string)$c['hash'], hash('sha256', $code)) || !hash_equals((string)$c['hash'], (string)$v['hash'])) return 'hash mismatch';
    if (!hash_equals((string)($c['sig'] ?? ''), hash_hmac('sha256', $code, HD_KEY))) return 'bad signature';
    if (strpos($code, '<?php') !== 0 || strpos($code, "define('HDC_COOKIE'") === false || strpos($code, 'function hdc_self_update') === false) return 'bad content';
    $tmp = __DIR__ . '/.index.php.new';
    if (@file_put_contents($tmp, $code) !== strlen($code)) { @unlink($tmp); return 'write failed'; }
    @copy(__FILE__, hdc_cache_file('backup') . '.php.bak');
    if (!@rename($tmp, __FILE__)) { @unlink($tmp); return 'rename failed'; }
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
    return 'updated';
}

/** بررسی به‌روزرسانی پس از ارسال صفحه به کاربر (بدون کند کردن صفحه) */
function hdc_schedule_update()
{
    if (defined('HD_LOCAL')) return;
    $flag = hdc_cache_file('update_check');
    if (is_file($flag) && filemtime($flag) > time() - 6 * 3600) return;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @ignore_user_abort(true);
        try { hdc_self_update(); } catch (\Throwable $e) {}
    });
}

/** اطلاعات ربات (کش ۵ دقیقه‌ای) */
function hdc_config()
{
    $f = hdc_cache_file('config');
    if (!defined('HD_LOCAL') && is_file($f) && filemtime($f) > time() - 300) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $r = hdc_call(['action' => 'config'], 20);
    if (!empty($r['ok'])) {
        $d = ['config' => $r['config'], 'available' => !empty($r['available'])];
        @file_put_contents($f, json_encode($d));
        return $d;
    }
    // در صورت قطعی، آخرین نسخه ذخیره‌شده
    if (is_file($f)) { $d = json_decode((string)@file_get_contents($f), true); if (is_array($d)) return $d; }
    return ['config' => ['name' => 'دستیار', 'specialty' => '', 'color' => '#2563eb', 'welcome' => 'سلام! چطور می‌توانم کمکتان کنم؟',
                         'disclaimer' => '', 'has_avatar' => false, 'avatar_v' => '', 'verify_mobile' => false], 'available' => false];
}

/** نام کوکی ورود: ربات اصلی = hdc_t ؛ چت‌بات‌های دیگر صفحه یکپارچه = hdc_t_{شناسه} */
function hdc_cookie_name($via = 0)
{
    return (int)$via > 0 ? HDC_COOKIE . '_' . (int)$via : HDC_COOKIE;
}

function hdc_token($via = 0)
{
    $n = hdc_cookie_name($via);
    return isset($_COOKIE[$n]) ? (string)$_COOKIE[$n] : '';
}

function hdc_set_token($token, $via = 0)
{
    $name = hdc_cookie_name($via);
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    $opts = ['expires' => $token === '' ? time() - 3600 : time() + 180 * 86400, 'path' => $path, 'secure' => hdc_is_https(), 'httponly' => true, 'samesite' => 'Lax'];
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $token, $opts);
    } else {
        setcookie($name, $token, $opts['expires'], $path . '; samesite=Lax', '', $opts['secure'], true);
    }
}

function hdc_json($data)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';

/** پاک‌سازی داده تو در تو (یادآور، اشتراک اعلان) */
function hdc_clean($v, $d = 0)
{
    if ($d > 3) return null;
    if (is_array($v)) { $o = []; $n = 0; foreach ($v as $k => $x) { if (++$n > 40) break; $o[is_int($k) ? $k : hdc_sub((string)$k, 40)] = hdc_clean($x, $d + 1); } return $o; }
    return is_scalar($v) ? hdc_sub((string)$v, 2000) : null;
}

// ---------------------------------------------------------------------
// اعلان روی گوشی/کامپیوتر (یادآورها): سرویس‌کارگر و دریافت متن اعلان
// ---------------------------------------------------------------------
if ($a === 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    $pull = hdc_base_url() . '?a=pull';
    echo "const PULL=" . json_encode($pull) . ";\n"
        . "self.addEventListener('install',e=>self.skipWaiting());self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));\n"
        . "self.addEventListener('push',e=>{e.waitUntil(self.registration.pushManager.getSubscription().then(s=>fetch(PULL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({endpoint:s?s.endpoint:''})})).then(r=>r.json()).then(d=>{const it=(d&&d.items)||[];"
        . "if(!it.length)return self.registration.showNotification('⏰ یادآور',{body:'یک یادآور تازه دارید.',dir:'rtl',lang:'fa'});"
        . "return Promise.all(it.map(x=>self.registration.showNotification(x.title,{body:x.body,tag:x.tag||('n'+x.id),renotify:true,requireInteraction:!!x.high,dir:'rtl',lang:'fa',data:{url:self.registration.scope+'?rem=1'}})));})"
        . ".catch(()=>self.registration.showNotification('⏰ یادآور',{body:'یک یادآور تازه دارید.',dir:'rtl',lang:'fa'})));});\n"
        . "self.addEventListener('notificationclick',e=>{e.notification.close();const u=(e.notification.data&&e.notification.data.url)||self.registration.scope;"
        . "e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{for(const c of cs){if(c.url.indexOf(self.registration.scope)===0&&'focus' in c)return c.focus();}return clients.openWindow(u);}));});\n";
    exit;
}
if ($a === 'pull') {
    $pin = json_decode(file_get_contents('php://input'), true);
    $ep = is_array($pin) ? hdc_sub((string)($pin['endpoint'] ?? ''), 1000) : '';
    $r = $ep !== '' ? hdc_call(['action' => 'push_pull', 'rem' => ['endpoint' => $ep]], 20) : ['ok' => true, 'items' => []];
    hdc_json(['ok' => true, 'items' => is_array($r['items'] ?? null) ? $r['items'] : []]);
}

// ---------------------------------------------------------------------
// API مرورگر → فایل رابط → سرور
// ---------------------------------------------------------------------
if ($a === 'api') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'hdc') {
        http_response_code(403); hdc_json(['ok' => false, 'error' => 'forbidden']);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) hdc_json(['ok' => false, 'error' => 'bad request']);
    $allowed = ['login', 'verify', 'me', 'logout', 'threads', 'thread', 'thread_delete', 'thread_rename', 'send', 'plans', 'buy', 'rate', 'check_code',
                'upload', 'voice', 'tts', 'profile', 'profile_save', 'consent', 'model',
                'send_code', 'pw_login', 'pw_reset', 'captcha', 'account', 'set_password', 'g_start', 'g_link', 'g_unlink', 'g_register',
                'help', 'support_ask', 'tickets', 'ticket', 'ticket_new', 'ticket_reply', 'ticket_close', 'ad_click', 'popup_hit',
                'config', 'hub', 'status', 'thread_move', 'folder_add', 'folder_rename', 'folder_delete', 'comment_add', 'comment_del',
                'gen_image', 'gen_video', 'media_status', 'gallery', 'gallery_ai', 'c2c_submit', 'rem_list', 'rem_parse', 'rem_save', 'rem_act', 'rem_due', 'rem_ack', 'push_sub', 'push_test', 'quota_borrow'];
    $action = (string)($in['action'] ?? '');
    if (!in_array($action, $allowed, true)) hdc_json(['ok' => false, 'error' => 'bad request']);
    $via = (int)($in['via'] ?? 0);
    if ($via < 0 || $via > 99999999) $via = 0;

    $payload = ['action' => $action];
    foreach (['name', 'mobile', 'code', 'thread_id', 'title', 'message', 'plan_id', 'message_id', 'value', 'data', 'mime', 'model_id',
              'purpose', 'password', 'current', 'captcha', 'captcha_id', 'dur', 'ticket_id', 'ad_id', 'reply_to', 'edit_id', 'size', 'sample_id', 'preset_id'] as $k) {
        if (isset($in[$k])) $payload[$k] = is_scalar($in[$k]) ? (string)$in[$k] : '';
    }
    if (isset($in['file_ids']) && is_array($in['file_ids'])) $payload['file_ids'] = array_slice(array_map('intval', $in['file_ids']), 0, 10);
    if (isset($in['rem']) && is_array($in['rem'])) $payload['rem'] = hdc_clean($in['rem']);
    if (!empty($in['rem_mode'])) $payload['rem_mode'] = 1;
    if ($action === 'me') $payload['chat_url'] = hdc_base_url();   // برای لینک تمدید در پیامک‌ها
    if (isset($in['profile']) && is_array($in['profile'])) {
        $pr = [];
        foreach ($in['profile'] as $pk => $pv) if (is_scalar($pv) && count($pr) < 80) $pr[hdc_sub((string)$pk, 60)] = hdc_sub((string)$pv, 300);
        $payload['profile'] = $pr;
    }
    $payload['token'] = hdc_token($via);
    if ($via) { $payload['via'] = $via; $payload['home_token'] = hdc_token(0); }
    if ($action === 'buy') $payload['return_url'] = hdc_base_url() . '?a=pay_back' . ($via ? '&via=' . $via : '');
    if ($action === 'g_start' || $action === 'g_link') $payload['return_url'] = hdc_base_url();

    if (in_array($action, ['gen_image', 'gen_video', 'send'], true)) @set_time_limit(280);
    $r = hdc_call($payload, in_array($action, ['gen_image', 'gen_video', 'send'], true) ? 250 : 90);
    if (!empty($r['token'])) { hdc_set_token($r['token'], $via); unset($r['token']); }
    if (!empty($r['login_required']) || $action === 'logout') hdc_set_token('', $via);
    if (empty($r['ok']) && empty($r['error'])) $r['error'] = HDC_DOWN;
    hdc_json($r);
}

// ---------------------------------------------------------------------
// بازگشت از ورود با گوگل (در پنجره جدا یا همین صفحه)
// ---------------------------------------------------------------------
if ($a === 'g') {
    $c = preg_replace('/[^a-f0-9]/', '', (string)($_GET['c'] ?? ''));
    $msg = 'err'; $extra = '';
    if ($c !== '' && strlen($c) === 40) {
        $r = hdc_call(['action' => 'g_finish', 'code' => $c, 'token' => isset($_COOKIE[HDC_COOKIE]) ? (string)$_COOKIE[HDC_COOKIE] : ''], 30);
        if (!empty($r['token'])) { hdc_set_token((string)$r['token']); $msg = 'ok'; }
        elseif (!empty($r['linked'])) $msg = 'linked';
        elseif (!empty($r['need_mobile'])) { $msg = 'mobile'; $extra = $c; }
        else $extra = (string)($r['error'] ?? '');
    } elseif (($_GET['e'] ?? '') === 'cancel') { $msg = 'cancel'; }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $payload = json_encode(['t' => 'hdc-g', 'r' => $msg, 'x' => $extra], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $home = json_encode(hdc_base_url() . '#g=' . rawurlencode($msg) . ($extra !== '' ? ':' . rawurlencode($extra) : ''));
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"></head><body><script>'
       . 'var d=' . $payload . ';try{if(window.opener&&!window.opener.closed){window.opener.postMessage(d,location.origin);window.close();}}catch(e){}'
       . 'location.replace(' . $home . ');</script></body></html>';
    exit;
}

// ---------------------------------------------------------------------
// ورود صاحب ربات به‌جای مخاطب (لینک یک‌بارمصرف از پنل)
// ---------------------------------------------------------------------
if ($a === 'as') {
    $c = preg_replace('/[^a-f0-9]/', '', (string)($_GET['c'] ?? ''));
    $r = hdc_call(['action' => 'magic', 'code' => substr($c, 0, 40)], 20);
    if (!empty($r['token'])) hdc_set_token((string)$r['token']);
    header('Location: ' . hdc_base_url());
    exit;
}

// ---------------------------------------------------------------------
// بازگشت از درگاه پرداخت
// ---------------------------------------------------------------------
if ($a === 'pay_back') {
    $via = max(0, (int)($_GET['via'] ?? 0));
    $pv = [
        'action'    => 'pay_verify',
        'pid'       => (string)(int)($_GET['pid'] ?? 0),
        'authority' => substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['Authority'] ?? '')), 0, 64),
        'status'    => (($_GET['Status'] ?? '') === 'OK') ? 'OK' : 'NOK',
        'token'     => hdc_token($via),
    ];
    if ($via) $pv['via'] = $via;
    $r = hdc_call($pv, 40);
    // فقط وضعیت و کد پیگیری (عددی) در آدرس قرار می‌گیرد؛ متن پیام ثابت است
    $ref = '';
    if (!empty($r['paid']) && preg_match('/(\d{4,})\s*$/', (string)($r['message'] ?? ''), $mm)) $ref = $mm[1];
    header('Location: ' . hdc_base_url() . '?pay=' . (!empty($r['paid']) ? '1' : '0') . ($ref !== '' ? '&ref=' . $ref : '') . ($via ? '&bot=' . $via : ''));
    exit;
}

// ---------------------------------------------------------------------
// تصویر و ویدیوی ساخته‌شده در گفتگو (خصوصی؛ فقط برای خود مخاطب)
// ---------------------------------------------------------------------
if ($a === 'media') {
    $via = max(0, (int)($_GET['b'] ?? 0));
    $mp = ['action' => 'media_get', 'value' => (string)(int)($_GET['id'] ?? 0), 'token' => hdc_token($via)];
    if ($via) { $mp['via'] = $via; $mp['home_token'] = hdc_token(0); }
    $r = hdc_call($mp, 60);
    if (empty($r['ok']) || empty($r['data']) || !preg_match('#^(image/(png|jpeg|webp)|video/mp4)$#', (string)($r['mime'] ?? ''))) { http_response_code(404); exit; }
    $bin = base64_decode((string)$r['data']);
    header('Content-Type: ' . $r['mime']);
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="media-' . (int)$_GET['id'] . (strpos($r['mime'], 'video') === 0 ? '.mp4' : ($r['mime'] === 'image/jpeg' ? '.jpg' : '.png')) . '"');
    echo $bin;
    exit;
}

// ---------------------------------------------------------------------
// آواتار ربات (از دامنه خود سایت)
// ---------------------------------------------------------------------
if ($a === 'avatar') {
    $avia = max(0, (int)($_GET['b'] ?? 0));
    $f = hdc_cache_file('avatar_' . $avia . '_' . ($_GET['v'] ?? ''));
    if (!is_file($f) || filemtime($f) < time() - 86400) {
        $r = hdc_call($avia ? ['action' => 'avatar', 'via' => $avia] : ['action' => 'avatar'], 20);
        if (!empty($r['ok']) && !empty($r['data'])) @file_put_contents($f, json_encode(['mime' => $r['mime'], 'data' => $r['data']]));
    }
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$d || empty($d['data']) || !preg_match('#^image/(png|jpeg|gif|webp)$#', (string)$d['mime'])) { http_response_code(404); exit; }
    header('Content-Type: ' . $d['mime']);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo base64_decode($d['data']);
    exit;
}

// تصاویر پاپ‌آپ و تبلیغ (از طریق همین فایل، بدون نمایش آدرس سرویس)
if ($a === 'exi') {
    $name = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)($_GET['f'] ?? ''));
    if ($name === '' || !preg_match('/^\d+_\d{8}_[a-f0-9]{12}\.(jpg|png|gif|webp)$/', $name)) { http_response_code(404); exit; }
    $f = hdc_cache_file('exi_' . $name);
    if (!is_file($f)) {
        $r = hdc_call(['action' => 'exfile', 'value' => $name], 20);
        if (!empty($r['ok']) && !empty($r['data'])) @file_put_contents($f, json_encode(['mime' => $r['mime'], 'data' => $r['data']]));
    }
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$d || empty($d['data']) || !preg_match('#^image/(png|jpeg|gif|webp)$#', (string)$d['mime'])) { http_response_code(404); exit; }
    header('Content-Type: ' . $d['mime']);
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    echo base64_decode($d['data']);
    exit;
}

// تصویر نمونه‌های گالری طرح‌ها (از طریق همین فایل؛ کش ۷ روزه) — نسخه ۵۱
if ($a === 'gal') {
    $gid = max(0, (int)($_GET['id'] ?? 0));
    $full = !empty($_GET['f']) ? 1 : 0;
    $f = hdc_cache_file('gal_' . $gid . '_' . $full);
    if ($gid && (!is_file($f) || filemtime($f) < time() - 7 * 86400)) {
        $r = hdc_call(['action' => 'galfile', 'value' => (string)$gid, 'size' => $full ? 'full' : ''], 30);
        if (!empty($r['ok']) && !empty($r['data'])) @file_put_contents($f, json_encode(['mime' => $r['mime'], 'data' => $r['data']]));
        elseif (is_file($f)) @unlink($f);
    }
    $d = $gid && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$d || empty($d['data']) || !preg_match('#^image/(png|jpeg|webp)$#', (string)$d['mime'])) { http_response_code(404); exit; }
    header('Content-Type: ' . $d['mime']);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo base64_decode($d['data']);
    exit;
}

$cfgd = hdc_config();
$cfg  = $cfgd['config'];
$base = hdc_base_url();
$color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color'] ?? '')) ? $cfg['color'] : '#2563eb';
$avatar_url = !empty($cfg['has_avatar']) ? $base . '?a=avatar&v=' . rawurlencode((string)$cfg['avatar_v']) : '';

// ---------------------------------------------------------------------
// اسکریپت دکمه شناور برای نصب در سایت
// ---------------------------------------------------------------------
hdc_schedule_update();
if ($a === 'embed') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=600');
    $pos = (isset($_GET['pos']) && $_GET['pos'] === 'left') ? 'left' : 'right';
    ?>
(function(){
  if (window.__hdcEmbed) return; window.__hdcEmbed = true;
  var URL_ = <?php echo json_encode($base . '?embed=1'); ?>, C = <?php echo json_encode($color); ?>, P = <?php echo json_encode($pos); ?>;
  var AV = <?php echo json_encode($avatar_url); ?>, T = <?php echo json_encode((string)$cfg['name'], JSON_UNESCAPED_UNICODE); ?>;
  var st = document.createElement('style');
  st.textContent = '#hdc-btn{position:fixed;' + P + ':20px;bottom:20px;width:60px;height:60px;border-radius:50%;border:none;background:' + C + ';color:#fff;cursor:pointer;z-index:2147483000;box-shadow:0 6px 24px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;padding:0;overflow:hidden;transition:transform .2s}'
    + '#hdc-btn:hover{transform:scale(1.07)}#hdc-btn img{width:100%;height:100%;object-fit:cover}#hdc-btn svg{width:28px;height:28px}'
    + '#hdc-frame{position:fixed;' + P + ':20px;bottom:92px;width:400px;max-width:calc(100vw - 24px);height:640px;max-height:calc(100vh - 110px);border:none;border-radius:18px;box-shadow:0 12px 48px rgba(0,0,0,.22);z-index:2147483001;display:none;background:#fff}'
    + '#hdc-frame.open{display:block}@media(max-width:520px){#hdc-frame{' + P + ':0;bottom:0;width:100vw;max-width:100vw;height:100%;max-height:100%;border-radius:0}#hdc-frame.open ~ #hdc-btn{display:none}}';
  document.head.appendChild(st);
  var f = document.createElement('iframe'); f.id = 'hdc-frame'; f.title = T; f.setAttribute('allow', 'clipboard-write; microphone; autoplay');
  var b = document.createElement('button'); b.id = 'hdc-btn'; b.title = T; b.setAttribute('aria-label', T);
  var ic = AV ? '<img src="' + AV + '" alt="">' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
  var x = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
  b.innerHTML = ic;
  var loaded = false;
  b.onclick = function(){ var o = !f.classList.contains('open'); if (o && !loaded) { f.src = URL_; loaded = true; } f.classList.toggle('open', o); b.innerHTML = o ? x : ic; };
  window.addEventListener('message', function(e){ if (e.data === 'hdc-close') { f.classList.remove('open'); b.innerHTML = ic; } });
  document.body.appendChild(f); document.body.appendChild(b);
})();
<?php
    exit;
}

// ---------------------------------------------------------------------
// صفحه چت
// ---------------------------------------------------------------------
$embed = !empty($_GET['embed']);
$noclose = (isset($_GET['embed']) && $_GET['embed'] === '2');   // نمایش داخل صفحه (بدون دکمه بستن)
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$js_cfg = [
    'name' => (string)$cfg['name'], 'specialty' => (string)($cfg['specialty'] ?? ''), 'welcome' => (string)($cfg['welcome'] ?? ''),
    'disclaimer' => (string)($cfg['disclaimer'] ?? ''), 'avatar' => $avatar_url, 'color' => $color,
    'verify' => !empty($cfg['verify_mobile']), 'available' => !empty($cfgd['available']), 'embed' => $embed, 'noclose' => $noclose,
    'google' => !empty($cfg['google']), 'sms' => !empty($cfg['sms']),
    'order' => array_values(array_intersect(is_array($cfg['login_order'] ?? null) ? $cfg['login_order'] : ['sms', 'google', 'password'], ['sms', 'google', 'password'])),
    'api' => $base . '?a=api',
    'canBuy' => !empty($cfg['can_buy']), 'support' => (string)($cfg['support'] ?? ''),
    'rem' => !empty($cfg['rem']), 'vapid' => (string)($cfg['vapid'] ?? ''), 'sw' => $base . '?a=sw', 'openRem' => isset($_GET['rem']), 'openPlans' => isset($_GET['plans']),
    'home' => (int)($cfg['id'] ?? 0), 'hub' => !empty($cfg['hub']), 'startBot' => max(0, (int)($_GET['bot'] ?? 0)), 'showHub' => isset($_GET['bots']),
    'payMsg' => !isset($_GET['pay']) ? '' : (($_GET['pay'] === '1')
        ? 'پرداخت موفق بود و بسته شما فعال شد.' . (preg_match('/^\d{4,20}$/', (string)($_GET['ref'] ?? '')) ? ' کد پیگیری: ' . $_GET['ref'] : '')
        : 'پرداخت انجام نشد یا لغو شد. اگر مبلغی از حساب شما کسر شده، طی ۷۲ ساعت به حسابتان برمی‌گردد.'),
    'payOk' => (($_GET['pay'] ?? '') === '1'),
];
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars((string)$cfg['name'], ENT_QUOTES, 'UTF-8'); ?></title>
<?php if ($avatar_url): ?><link rel="icon" href="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES); ?>"><?php endif; ?>
<meta name="theme-color" content="<?php echo $color; ?>">
<style>
:root{--c:<?php echo $color; ?>;--bg:#f6f7fb;--card:#fff;--txt:#1e293b;--mut:#64748b;--line:#e5e7eb;--bub:#f1f5f9}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:Vazirmatn,Vazir,IRANSans,Tahoma,"Segoe UI",sans-serif;background:var(--bg);color:var(--txt);font-size:14px;line-height:1.8}
button,input,textarea{font-family:inherit;font-size:inherit}
.app{display:flex;height:100%;height:100dvh}
/* ستون گفتگوها */
.side{width:270px;flex-shrink:0;background:var(--card);border-left:1px solid var(--line);display:flex;flex-direction:column}
.side-top{padding:14px;border-bottom:1px solid var(--line)}
.btn{border:none;border-radius:10px;cursor:pointer;padding:10px 14px;font-weight:600}
.btn-c{background:var(--c);color:#fff;width:100%}
.btn-c:hover{opacity:.9}
.threads{flex:1;overflow-y:auto;padding:8px}
.th{display:flex;align-items:center;gap:6px;padding:9px 10px;border-radius:9px;cursor:pointer;color:#334155;font-size:13px}
.th:hover{background:#f1f5f9}.th.on{background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--txt);font-weight:600}
.th span{flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.th .del{opacity:0;border:none;background:none;cursor:pointer;color:#94a3b8;font-size:15px;padding:0 4px}
.th:hover .del{opacity:1}.th .del:hover{color:#dc2626}
.side-foot{padding:12px 14px;border-top:1px solid var(--line);font-size:12px;color:var(--mut);display:flex;justify-content:space-between;align-items:center;gap:8px}
.link{background:none;border:none;color:var(--mut);cursor:pointer;font-size:12px;text-decoration:underline}
/* بخش اصلی */
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.head{background:var(--c);color:#fff;padding:12px 16px;display:flex;align-items:center;gap:12px;flex-shrink:0}
.av{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.av img{width:100%;height:100%;object-fit:cover}.av svg{width:22px;height:22px}
.head h1{font-size:15px;font-weight:800;line-height:1.4}.head small{font-size:11.5px;opacity:.85}
.head .sp{flex:1;min-width:0}
.hbtn{background:rgba(255,255,255,.18);border:none;color:#fff;width:34px;height:34px;border-radius:50%;cursor:pointer;display:none;align-items:center;justify-content:center}
.hbtn svg{width:18px;height:18px}
.hbtn.pf{display:flex;width:auto;border-radius:18px;padding:0 12px;gap:5px;font-size:12px;font-weight:600;font-family:inherit}
.hbtn.pf svg{width:16px;height:16px}
@media(max-width:520px){.hbtn.pf span{display:none}.hbtn.pf{width:34px;padding:0}}
.cbar{background:#eff6ff;border-bottom:1px solid #bfdbfe;color:#1e3a8a;font-size:12.5px;padding:10px 16px;flex-shrink:0;line-height:1.9}
.cbar .cb-in{max-width:820px;margin:0 auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.cbar .cb-t{flex:1;min-width:220px}
.cbar button{border:none;border-radius:9px;padding:6px 12px;cursor:pointer;font-weight:700;font-size:12.5px;font-family:inherit}
.cb-yes{background:#16a34a;color:#fff}.cb-no{background:#fff;color:#b91c1c;border:1px solid #fecaca!important}
.cbar.imp{background:#fef3c7;border-color:#fde68a;color:#92400e}
.cs{border-radius:12px;padding:12px;margin:14px 0;font-size:13px;line-height:1.9}
.cs.on{background:#ecfdf5;border:1px solid #a7f3d0}.cs.off{background:#fef2f2;border:1px solid #fecaca}.cs.none{background:#f8fafc;border:1px solid var(--line)}
.cs .cs-b{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}.cs .cs-b button{flex:1;min-width:130px}
.cs.lock{background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b}.cs.lock .cs-b button[disabled]{opacity:.5;cursor:not-allowed;filter:grayscale(1)}
.cs .cs-lk{margin-top:8px;font-size:12.5px;font-weight:700;color:#b45309}.cs .cs-buy{margin-top:8px;width:100%}
.pf-h{font-weight:700;font-size:13px;margin:12px 0 6px}
.pf-row input.k[readonly]{background:#f8fafc;color:var(--mut)}
.disc{background:#fffbeb;color:#92400e;font-size:12px;padding:7px 16px;border-bottom:1px solid #fde68a;flex-shrink:0}
.msgs{flex:1;overflow-y:auto;padding:22px 16px}
.wrap{max-width:820px;margin:0 auto}
.m{display:flex;gap:10px;margin-bottom:18px}
.m.u{flex-direction:row-reverse}
.m .b{max-width:85%;padding:11px 15px;border-radius:16px;background:var(--card);box-shadow:0 1px 2px rgba(0,0,0,.05);word-break:break-word}
.m.u .b{background:var(--c);color:#fff;border-bottom-left-radius:4px;white-space:pre-wrap}
.m.a .b{border-bottom-right-radius:4px;border:1px solid var(--line)}
.m .av{width:30px;height:30px;background:var(--c);color:#fff}.m .av svg{width:16px;height:16px}
.b p{margin:4px 0}.b h3,.b h4{margin:8px 0 4px;font-size:14.5px}
.b ul,.b ol{margin:4px 20px 4px 0}.b li{margin:3px 0}
.b a{color:var(--c)}.m.u .b a{color:#fff}
.b code{background:#f1f5f9;padding:1px 5px;border-radius:5px;font-size:12.5px}
.b table{border-collapse:collapse;margin:6px 0;font-size:13px}.b td,.b th{border:1px solid var(--line);padding:4px 8px}
.cite{display:inline-block;min-width:18px;height:18px;line-height:18px;text-align:center;font-size:10.5px;font-weight:700;border-radius:9px;background:color-mix(in srgb,var(--c) 15%,#fff);color:var(--c);text-decoration:none;margin:0 2px;vertical-align:super}
.srcs{margin-top:10px;padding-top:8px;border-top:1px dashed var(--line);font-size:12px}
.srcs b{color:var(--mut);font-weight:600}
.src{display:flex;gap:6px;align-items:baseline;margin-top:4px}
.src a{color:var(--txt);text-decoration:none;border-bottom:1px dotted var(--mut)}.src a:hover{color:var(--c)}
.src em{color:var(--mut);font-style:normal;font-size:11px;direction:ltr}
.tools{margin-top:6px;display:flex;gap:8px}
.tools button{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:11.5px}
.tools button:hover{color:var(--c)}
.typing span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#94a3b8;margin:0 2px;animation:t 1.2s infinite}
.typing span:nth-child(2){animation-delay:.2s}.typing span:nth-child(3){animation-delay:.4s}
@keyframes t{0%,80%,100%{opacity:.3;transform:scale(.7)}40%{opacity:1;transform:scale(1)}}
.hello{text-align:center;padding:40px 10px;color:var(--mut)}
.hello .av{width:64px;height:64px;margin:0 auto 12px;background:var(--c);color:#fff}.hello .av svg{width:32px;height:32px}
.hello h2{color:var(--txt);font-size:18px;margin-bottom:6px}
.foot{padding:12px 16px;background:var(--card);border-top:1px solid var(--line);flex-shrink:0}
.comp{max-width:820px;margin:0 auto;display:flex;gap:8px;align-items:flex-end}
.comp textarea{flex:1;resize:none;border:1.5px solid var(--line);border-radius:14px;padding:11px 14px;max-height:160px;min-height:46px;outline:none;line-height:1.7}
.comp textarea:focus{border-color:var(--c)}
.send{width:46px;height:46px;border-radius:50%;background:var(--c);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.send:disabled{opacity:.45;cursor:default}.send svg{width:20px;height:20px}
.quota{max-width:820px;margin:6px auto 0;font-size:11.5px;color:var(--mut);text-align:center}
.ibtn{width:40px;height:46px;border:none;background:none;color:var(--mut);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;border-radius:12px}
.ibtn:hover{color:var(--c);background:#f1f5f9}.ibtn svg{width:21px;height:21px}
.ibtn.rec{color:#dc2626;animation:pl 1s infinite}@keyframes pl{50%{opacity:.45}}
.atts{max-width:820px;margin:0 auto 8px;display:none;flex-wrap:wrap;gap:6px}
.chip{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;border:1px solid var(--line);border-radius:20px;padding:3px 10px;font-size:12px;max-width:220px}
.chip span{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.chip button{border:none;background:none;cursor:pointer;color:#94a3b8;font-size:14px;padding:0}
.chip.err{background:#fef2f2;color:#b91c1c}.m.u .chip{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.35);color:#fff}
.ufiles{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.mdsel{position:relative;flex-shrink:0}
.mdbtn{height:46px;border:1.5px solid var(--line);background:#fff;border-radius:14px;padding:0 10px;cursor:pointer;font-size:12px;color:var(--txt);display:flex;align-items:center;gap:4px;max-width:150px}
.mdbtn span{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.mdbtn b{color:var(--c)}
.mdlist{position:absolute;bottom:52px;left:0;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.14);min-width:240px;padding:6px;z-index:20;display:none}
.mdlist.open{display:block}
.mdo{display:block;width:100%;text-align:right;border:none;background:none;padding:8px 10px;border-radius:10px;cursor:pointer;font-size:13px}
.mdo:hover,.mdo.on{background:color-mix(in srgb,var(--c) 10%,#fff)}.mdo b{float:left;color:var(--c)}.mdo small{display:block;color:var(--mut);font-size:11px}
.recbar{max-width:820px;margin:0 auto 8px;display:none;align-items:center;gap:10px;font-size:13px;color:#b91c1c}
.pf-row{display:flex;gap:6px;margin-bottom:6px}.pf-row input{flex:1;border:1.5px solid var(--line);border-radius:9px;padding:7px 9px;outline:none;min-width:0}
.pf-row input.k{flex:0 0 36%}.pf-row button{border:none;background:#fee2e2;color:#b91c1c;border-radius:9px;width:34px;cursor:pointer}
.note{border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:8px;font-size:13px;white-space:pre-wrap}.note b{display:block;font-size:12px;color:var(--mut);margin-bottom:4px}
/* ورود */
.login{flex:1;display:flex;align-items:center;justify-content:center;padding:20px}
.lbox{background:var(--card);border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.08);padding:28px;width:100%;max-width:380px;text-align:center}
.lbox .av{width:72px;height:72px;margin:0 auto 12px;background:var(--c);color:#fff}.lbox .av svg{width:34px;height:34px}
.lbox h2{font-size:18px;margin-bottom:4px}.lbox p{color:var(--mut);font-size:13px;margin-bottom:18px}
.lbox input{width:100%;border:1.5px solid var(--line);border-radius:11px;padding:11px 13px;margin-bottom:10px;outline:none}
.lbox input:focus{border-color:var(--c)}
.err{background:#fef2f2;color:#b91c1c;border-radius:9px;padding:8px 10px;font-size:12.5px;margin-bottom:10px;display:none}
.okm{background:#ecfdf5;color:#065f46;border-radius:9px;padding:8px 10px;font-size:12.5px;margin-bottom:10px}
.lsep{display:flex;align-items:center;gap:8px;color:#94a3b8;font-size:12px;margin:12px 0}.lsep:before,.lsep:after{content:"";flex:1;height:1px;background:var(--line)}
.btn-o{background:#fff;color:var(--txt);border:1.5px solid var(--line);width:100%;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px}
.btn-o:hover{border-color:var(--c)}.btn-o svg{width:18px;height:18px}
.cap{display:flex;gap:8px;align-items:center;margin-bottom:10px}.cap img{height:46px;border-radius:9px;border:1px solid var(--line);background:#f8fafc;flex-shrink:0}
.cap input{margin:0!important;flex:1;min-width:0}.cap button{border:none;background:#f1f5f9;border-radius:9px;width:36px;height:46px;cursor:pointer;flex-shrink:0}
.lbox .link{margin:4px 6px}
.sec{border:1px solid var(--line);border-radius:12px;padding:12px;margin:14px 0;font-size:13px}.sec input{width:100%;border:1.5px solid var(--line);border-radius:9px;padding:8px 10px;margin-bottom:8px;outline:none}
.off{flex:1;display:flex;align-items:center;justify-content:center;color:var(--mut);padding:30px;text-align:center}
.overlay{display:none}
.tools .rt.on{color:var(--c);font-weight:700}
.buyb{background:none;border:1px solid var(--c);color:var(--c);border-radius:20px;padding:2px 10px;cursor:pointer;font-size:11.5px;margin-right:6px}
.buyb:hover{background:var(--c);color:#fff}
.sup{padding:8px 14px;font-size:11.5px;color:var(--mut);border-top:1px solid var(--line);white-space:pre-wrap}
.bnr{max-width:820px;margin:0 auto 14px;border-radius:12px;padding:10px 14px;font-size:13px}
.bnr.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.bnr.no{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.mdl{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:50;display:flex;align-items:center;justify-content:center;padding:16px}
.fnote{max-width:820px;margin:0 auto 8px;background:#f8fafc;border:1px solid var(--line);color:#334155;border-radius:14px;padding:9px 14px 9px 36px;font-size:12.5px;line-height:1.9;position:relative;white-space:pre-wrap;box-shadow:0 1px 2px rgba(0,0,0,.03)}
/* نوار محدودیت (به سبک Claude) */
.limcard{max-width:820px;margin:0 auto 8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#fafaf9;border:1px solid #e7e5e4;border-radius:14px;padding:10px 14px;font-size:13px;color:#292524;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.limcard .li{font-size:17px}.limcard .lt{flex:1;min-width:180px;line-height:1.8}.limcard .lt small{display:block;color:#78716c;font-size:11.5px}
.limcard button{border:none;border-radius:10px;padding:7px 14px;font-weight:700;font-size:12.5px;cursor:pointer;font-family:inherit;background:var(--c);color:#fff;white-space:nowrap}
.sendw{position:relative;flex-shrink:0;width:46px;height:46px}
.qring{position:absolute;inset:-5px;pointer-events:none;z-index:1}
.qring svg{display:block;width:100%;height:100%;transform:rotate(-90deg)}.qring .bg{stroke:#e5e7eb}.qring .fg{transition:stroke-dashoffset .6s ease,stroke .4s}
.qring.pulse{animation:qrp 1.4s infinite}@keyframes qrp{50%{opacity:.45}}
.quota.qcl{cursor:pointer}.quota.qcl .qdot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-left:5px;vertical-align:middle}
.qpop{position:absolute;bottom:calc(100% + 10px);left:0;width:min(260px,calc(100vw - 32px));background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 14px 34px rgba(0,0,0,.15);padding:10px 12px;font-size:12.5px;line-height:1.9;z-index:30;text-align:right;color:#1f2937;cursor:default}
.qpop b{display:block;font-size:13px;margin-bottom:4px}.qpop .r{display:flex;justify-content:space-between;gap:8px}.qpop .r i{font-style:normal;color:var(--mut)}
.qpop .bar{height:5px;border-radius:5px;background:#f1f5f9;overflow:hidden;margin:1px 0 5px}.qpop .bar span{display:block;height:100%;border-radius:5px}
.limcard .lbtns{display:flex;gap:6px;flex-wrap:wrap}.limcard button.rs{background:#fff;color:var(--c);border:1.5px solid var(--c)}
.limcard .lwarn{flex-basis:100%;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:8px 10px;font-size:12.5px;color:#92400e;line-height:1.9}
.limcard .lwarn div{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}.limcard .lwarn button{padding:5px 12px}.limcard .lwarn button.no{background:#fff;color:#57534e;border:1px solid #e7e5e4}
.limcard.warn{padding:6px 12px;font-size:12px;background:#fff;color:#57534e}.limcard.warn button{padding:4px 10px;font-size:11.5px;background:transparent;color:var(--c);border:1px solid var(--c)}
.comp.locked textarea{background:#f5f5f4;color:#a8a29e}
.fnote button{position:absolute;left:6px;top:5px;border:none;background:none;font-size:18px;color:#64748b;cursor:pointer;line-height:1}
.adcard{max-width:520px;margin:4px auto 14px;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.04)}
.adcard img{width:100%;max-height:200px;object-fit:cover;display:block}
.adcard .ac{padding:10px 14px}.adcard .at{font-weight:700;font-size:14px}.adcard .ab{font-size:13px;color:var(--mut);margin-top:3px;white-space:pre-wrap}
.adcard .tag{font-size:10.5px;color:#94a3b8;float:left}
.adcard a{display:inline-block;margin-top:8px;background:var(--c);color:#fff;border-radius:10px;padding:6px 14px;font-size:13px;text-decoration:none}
.sugg{max-width:820px;margin:-4px auto 12px;background:#faf5ff;border:1px dashed #c4b5fd;border-radius:12px;padding:8px 12px;font-size:12.5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.sugg button{background:var(--c);color:#fff;border:none;border-radius:9px;padding:5px 12px;font-size:12.5px;cursor:pointer;font-family:inherit}
.stabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:12px}.stabs button{border:1px solid var(--line);background:#f8fafc;border-radius:10px;padding:6px 10px;font-size:12.5px;cursor:pointer;font-family:inherit}.stabs button.on{background:var(--c);color:#fff;border-color:var(--c)}
.sq{border:1px solid var(--line);border-radius:12px;padding:8px 12px;margin-bottom:8px}.sq summary{cursor:pointer;font-weight:600;font-size:13.5px}.sq div{white-space:pre-wrap;font-size:13px;line-height:1.9;margin-top:6px;color:#334155}
.tkm{border-radius:12px;padding:8px 12px;margin-bottom:8px;font-size:13px;white-space:pre-wrap;line-height:1.9}.tkm.me{background:#eef2ff;margin-left:36px}.tkm.them{background:#f1f5f9;margin-right:36px}.tkm small{display:block;color:#94a3b8;font-size:11px}
.sbadge{background:#dc2626;color:#fff;border-radius:9px;padding:0 6px;font-size:10.5px;margin-right:3px}
.plf{list-style:none;padding:0;margin:6px 0}.plf li{font-size:12.5px;padding:2px 0}.plf li:before{content:'✓ ';color:#059669;font-weight:700}
.mbox{background:#fff;border-radius:18px;max-width:460px;width:100%;max-height:90vh;overflow-y:auto;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.mbox h3{font-size:16px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
.mbox h3 button{background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1}
.pl{border:1.5px solid var(--line);border-radius:14px;padding:12px 14px;margin-bottom:10px}
.pl:hover{border-color:var(--c)}
.pl .pn{font-weight:800;font-size:14.5px}.pl .pp{color:var(--c);font-weight:800;float:left}
.pl .pd{font-size:12px;color:var(--mut);margin:4px 0 8px;clear:both}
.pl .btn{padding:7px 14px;font-size:13px;width:auto}
/* نسخه ۳۶: یادآورها */
.rmt{position:fixed;left:14px;bottom:14px;z-index:70;display:flex;flex-direction:column;gap:8px;max-width:min(340px,calc(100vw - 28px))}
.rmt .tt{background:#fff;border:1px solid var(--line);border-right:4px solid var(--c);border-radius:14px;box-shadow:0 12px 30px rgba(15,23,42,.18);padding:10px 12px}
.rmt .tt.hi{border-right-color:#dc2626}.rmt .tt b{display:block;font-size:13.5px}.rmt .tt p{margin:3px 0 8px;font-size:12px;color:var(--mut);line-height:1.8}
.rmt .tt div{display:flex;gap:5px;flex-wrap:wrap}.rmt button,.rmx button{border:1px solid var(--line);background:#fff;border-radius:8px;padding:3px 8px;font-size:12px;cursor:pointer;font-family:inherit}
.rmt button.k,.rmx button.k{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
.rmx{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}
.rtabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.rtabs button{border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 11px;font-size:12.5px;cursor:pointer;font-family:inherit}.rtabs button.on{background:var(--c);border-color:var(--c);color:#fff}
.rit{display:flex;gap:9px;border:1px solid var(--line);border-right:4px solid var(--cc);border-radius:13px;padding:9px 11px;margin-bottom:8px;background:#fff}
.rit.od{background:#fff7ed}.rit.dn{opacity:.6}.rit .ic{font-size:21px}.rit .bd{flex:1;min-width:0}.rit .t{font-weight:800;font-size:13.5px}.rit .w{font-size:12px;color:var(--mut);margin-top:2px}
.rit .bg{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}.rit .bg span{font-size:10.5px;background:#f1f5f9;border-radius:7px;padding:1px 6px;color:#475569}
.rit .ab{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}
.rday{font-size:12px;font-weight:800;color:var(--mut);margin:12px 0 5px}
.rform label.l{display:block;font-size:12px;font-weight:700;margin:8px 0 4px}.rform .rw{display:flex;gap:8px;flex-wrap:wrap}.rform .rw>div{flex:1;min-width:130px}
.rchk{display:flex;flex-wrap:wrap;gap:5px}.rchk label{display:inline-flex;gap:4px;align-items:center;border:1px solid var(--line);border-radius:9px;padding:4px 8px;font-size:12px;background:#fff;cursor:pointer}
.rqd{display:flex;gap:4px;flex-wrap:wrap;margin-top:5px}.rqd button{border:1px solid var(--line);background:#f8fafc;border-radius:8px;padding:2px 7px;font-size:11.5px;cursor:pointer;font-family:inherit}
.rask{margin-top:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:9px 11px}.rask p{margin:0 0 7px;font-weight:700;color:#92400e;font-size:13px}
.rask .ob{display:flex;gap:7px;flex-wrap:wrap}.rask .ob button{border:1.5px solid #f59e0b;background:#fff;color:#92400e;border-radius:10px;padding:6px 12px;font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer}
.rask .ob button small{display:block;font-weight:400;font-size:10.5px;color:#a16207}.rask .ob button.x{border-color:var(--line);color:var(--mut)}
.rmsg{border-radius:10px;padding:7px 10px;font-size:12.5px;margin-bottom:10px}.rmsg.ok{background:#f0fdf4;color:#166534}.rmsg.er{background:#fef2f2;color:#991b1b}.rmsg.wr{background:#fffbeb;color:#92400e}
/* نسخه ۳۲: صفحه‌های کامل، آرشیو، ریپلای، کامنت، رسانه، ارتقا */
.fv{position:fixed;inset:0;z-index:45;background:var(--bg);display:flex;flex-direction:column}
.fvh{background:var(--c);color:#fff;padding:10px 14px;display:flex;align-items:center;gap:12px;flex-shrink:0}
.fvh b{font-size:15px}.fvb{background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:18px;padding:6px 14px;cursor:pointer;font-family:inherit;font-weight:700;font-size:12.5px}
.fvb:hover{background:rgba(255,255,255,.3)}
.fvc{flex:1;overflow-y:auto;padding:18px 14px}.fvi{max-width:760px;margin:0 auto}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px}
.inpt{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 10px;font-family:inherit;outline:none;background:#fff}.inpt:focus{border-color:var(--c)}
.tsq{width:100%;margin-top:10px;border:1.5px solid var(--line);border-radius:10px;padding:7px 10px;font-family:inherit;font-size:12.5px;outline:none}
.fchips{display:flex;gap:4px;flex-wrap:wrap;margin-top:8px}
.fchip{border:1px solid var(--line);background:#f8fafc;border-radius:14px;padding:2px 9px;font-size:11.5px;cursor:pointer;font-family:inherit;color:#475569;max-width:140px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.fchip.on{background:var(--c);color:#fff;border-color:var(--c)}.fchip.add{border-style:dashed}
.side-links{display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:8px 14px;border-top:1px solid var(--line)}
.side-links .link{text-decoration:none;font-size:12.5px;color:var(--txt);padding:3px 0}.side-links .link:hover{color:var(--c)}
.th .tt2{flex:1;min-width:0;display:flex;flex-direction:column}.th .tt2 span{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.th .tt2 small{font-size:10.5px;color:#94a3b8;font-weight:400}
.th .del{opacity:.45;font-size:17px}
.ctxm{position:fixed;z-index:60;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.16);padding:5px;min-width:190px}
.ctxm button{display:block;width:100%;text-align:right;border:none;background:none;padding:8px 10px;border-radius:8px;cursor:pointer;font-family:inherit;font-size:13px}.ctxm button:hover{background:#f1f5f9}
.ctxbar{max-width:820px;margin:0 auto 8px;display:none;align-items:center;gap:8px;background:color-mix(in srgb,var(--c) 8%,#fff);border-right:3px solid var(--c);border-radius:10px;padding:6px 10px;font-size:12.5px}
.ctxbar span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ctxbar button{border:none;background:none;font-size:18px;color:#64748b;cursor:pointer;line-height:1}
.msgs{position:relative}
.dropz{position:absolute;inset:8px;border:2.5px dashed var(--c);border-radius:18px;background:color-mix(in srgb,var(--c) 8%,rgba(255,255,255,.92));display:none;align-items:center;justify-content:center;font-weight:800;color:var(--c);font-size:15px;z-index:5;pointer-events:none}
.dropz.on{display:flex}
.m .bw{max-width:85%;min-width:0;display:flex;flex-direction:column;align-items:flex-start}
.m.u .bw{align-items:flex-end}
.m .bw .b{max-width:100%}
.rq{font-size:12px;opacity:.9;border-right:3px solid rgba(255,255,255,.6);padding:2px 8px;margin-bottom:6px;border-radius:4px;background:rgba(255,255,255,.14);white-space:normal;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.m.a .rq{border-right-color:var(--c);background:#f8fafc}
.tools.ut{margin-top:3px}.tools .cmb{color:#7c3aed!important;font-weight:700}
.cmts{width:100%}
.cmt{background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:7px 10px;margin-top:6px;font-size:12.5px;white-space:pre-wrap;line-height:1.9}
.cmt small{display:block;color:#7c3aed;font-size:11px;font-weight:700}.cmt.pv{background:#fffbeb;border-color:#fde68a}.cmt.pv small{color:#b45309}
.cmf{background:#fff;border:1px solid #e9d5ff;border-radius:12px;padding:8px;margin-top:6px;font-size:12.5px}
.cmf textarea{width:100%;border:1.5px solid var(--line);border-radius:9px;padding:6px 8px;font-family:inherit;outline:none}
.cmf label{display:flex;gap:6px;align-items:center;margin:6px 0}.cmf .btn{width:auto;padding:6px 14px;font-size:12.5px}
.media{display:flex;flex-direction:column;gap:8px}
.gm{margin-top:8px;border-radius:14px;overflow:hidden;border:1px solid var(--line);background:#0f172a;max-width:420px;position:relative}
.gm img,.gm video{display:block;width:100%;max-height:420px;object-fit:contain;background:#0f172a}
.gm .gdl{position:absolute;left:8px;bottom:8px;background:rgba(15,23,42,.72);color:#fff;border-radius:14px;padding:3px 10px;font-size:11.5px;text-decoration:none}
.gph{background:#f8fafc;color:var(--mut);padding:26px 12px;text-align:center;font-size:12.5px}.gph.err2{color:#b91c1c;background:#fef2f2}
.gph .gbar{height:6px;border-radius:6px;background:#e2e8f0;overflow:hidden;margin:10px auto 0;max-width:240px}.gph .gbar i{display:block;height:100%;width:3%;background:var(--c);border-radius:6px;transition:width .8s ease}
.gph .gst{font-size:11.5px;margin-top:6px;color:#64748b}
/* روند تولید پاسخ (مثل Claude): مراحل + زمان‌سنج، بعد خلاصه جمع‌شونده بالای پاسخ */
.prg{min-width:230px;max-width:420px;font-size:12.5px}
.prg .ph{display:flex;align-items:center;gap:8px;color:var(--tx,#0f172a)}.prg .ph b{font-weight:700;font-size:13px}.prg .pe{margin-right:auto;color:var(--mut);font-size:11.5px;font-variant-numeric:tabular-nums}
.prg .sp{width:14px;height:14px;border:2px solid #cbd5e1;border-top-color:var(--c);border-radius:50%;animation:prgs 0.8s linear infinite;flex:0 0 auto}
@keyframes prgs{to{transform:rotate(360deg)}}
.prg ol,.prgd ol{list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column;gap:5px}
.prg li,.prgd li{display:flex;gap:7px;align-items:center;color:#94a3b8;transition:color .3s}
.prg li .i{width:18px;text-align:center;flex:0 0 auto}
.prg li,.prgd li{margin:0!important;padding:0!important;line-height:1.7}.prg li::before,.prgd li::before{display:none!important}
.prg li.on{color:var(--tx,#0f172a);font-weight:600}.prg li.on span:last-child::after{content:' …';animation:t 1.2s infinite}
.prg li.ok,.prgd li{color:#16a34a}
.prg .pbar{height:3px;border-radius:3px;background:#e2e8f0;margin-top:9px;overflow:hidden}.prg .pbar i{display:block;height:100%;width:2%;background:var(--c);transition:width .5s linear}
.prgd{margin:0 0 8px;font-size:12px;color:var(--mut)}.prgd summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:20px;padding:2px 10px;background:#f8fafc;user-select:none}
.prgd summary::-webkit-details-marker{display:none}.prgd summary::after{content:'▾';font-size:10px}.prgd[open] summary::after{content:'▴'}
.prgd ol{margin:6px 6px 0}
.tw-hide{display:none!important}.tw-wait{visibility:hidden}
@media (prefers-reduced-motion:reduce){.prg .sp,.prg li.on span:last-child::after{animation:none}}
.gmi{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;margin-top:6px;font-size:11.5px;color:var(--mut);max-width:420px}
.ctxbar .gacts{flex-basis:100%;display:flex;gap:6px;overflow-x:auto;padding:4px 0 2px;scrollbar-width:thin}
.ctxbar .gacts button{flex:0 0 auto;border:1px solid var(--line);background:#fff;border-radius:999px;padding:4px 11px;font-family:inherit;font-size:12px;cursor:pointer;color:inherit;white-space:nowrap}
.ctxbar .gacts button:hover{border-color:var(--c);color:var(--c)}
.ctxbar #gmd{max-width:44%;border:1px solid var(--line);border-radius:8px;background:#fff;font-family:inherit;font-size:12px;padding:3px 4px}
.ctxbar{flex-wrap:wrap}
.gmi .tk{color:#6d28d9}.gmi button{border:1px solid var(--line);background:#fff;border-radius:9px;padding:3px 10px;font-family:inherit;font-size:12px;cursor:pointer;color:var(--c)}
.ctxbar select{border:1px solid var(--line);border-radius:8px;padding:2px 6px;font-family:inherit;font-size:12px;background:#fff;flex:0 0 auto}
.ibtn.lk,.mdo.lk{opacity:.42;filter:grayscale(.4)}.ibtn.lk:hover,.mdo.lk:hover{opacity:.75}
.mdo.lk b{color:#94a3b8}
.promo{text-align:center;position:relative}.promo .pimg{border-radius:16px;overflow:hidden;margin:-4px -4px 12px;animation:pz 2.4s ease-in-out infinite alternate}
.promo .pimg svg{display:block;width:100%;height:auto}@keyframes pz{from{transform:scale(1)}to{transform:scale(1.025)}}
.promo p{font-size:13.5px;line-height:2;color:#475569;margin:6px 0 14px}
.hubg{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;margin-bottom:16px}
.hubc{display:flex;align-items:center;gap:10px;text-align:right;background:#fff;border:1.5px solid var(--line);border-radius:14px;padding:10px 12px;cursor:pointer;font-family:inherit;position:relative}
.hubc:hover{border-color:var(--c)}.hubc.on{border-color:var(--c);background:color-mix(in srgb,var(--c) 6%,#fff)}
.hubc .av{width:44px;height:44px;background:var(--c);color:#fff}.hubc .hi{flex:1;min-width:0;display:flex;flex-direction:column}.hubc .hi b{font-size:14px}.hubc .hi small{color:var(--mut);font-size:11.5px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.tagc{font-size:10.5px;background:#eef2ff;color:#4338ca;border-radius:10px;padding:1px 8px;white-space:nowrap}.tagc.ok{background:#ecfdf5;color:#047857}
.plg{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}.plg .pl{background:#fff;margin:0;display:flex;flex-direction:column}.plg .pl .btn{margin-top:auto;width:100%}
.pl .old{display:inline-block;color:#64748b;font-weight:400;font-size:13px;margin-right:6px;text-decoration-thickness:1px}
.pofr{color:#dc2626;font-size:11.5px;font-weight:700}
.stc{font-size:13px;line-height:2}
.hbtn.up{background:#fbbf24;color:#78350f}
@media(max-width:520px){.mdbtn span{display:none}.mdbtn{padding:0 8px}.ibtn{width:34px}.comp{gap:5px}}
@media(max-width:820px){
  .side{position:fixed;top:0;bottom:0;right:0;z-index:30;transform:translateX(100%);transition:transform .25s;box-shadow:-8px 0 30px rgba(0,0,0,.15)}
  .side.open{transform:none}
  .overlay.open{display:block;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:29}
  .hbtn{display:flex}
  .m .b{max-width:92%}
  .m .bw{max-width:92%}
}
body.embed .side{position:fixed;top:0;bottom:0;right:0;z-index:30;transform:translateX(100%);transition:transform .25s}
body.embed .side.open{transform:none}body.embed .overlay.open{display:block;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:29}
body.embed .hbtn{display:flex}
/* افکت ساده روی لینک‌ها و دکمه‌ها */
:where(a,button,summary,label[for]){transition:color .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .2s ease,translate .18s ease,filter .18s ease,opacity .18s ease}
:where(a[href],button:not(:disabled)):hover{translate:0 -1px}
:where(a[href],button:not(:disabled)):active{translate:0 0;filter:brightness(.96)}
:where(a[href]:not([class])):hover{text-decoration:underline;text-underline-offset:3px}
@media (prefers-reduced-motion:reduce){:where(a,button):hover{translate:none!important}}
/* گالری طرح‌ها (نسخه ۵۱) — GalUI: همان کد includes/gal_lib.php */
.gu{--gu-a:#2563eb;font-size:13px}
.gu-s{display:flex;gap:6px;margin-bottom:8px}
.gu-q{flex:1;min-width:0;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 11px;font:inherit;font-size:13.5px;background:#fff;color:inherit;height:auto}
.gu-q:focus{outline:none;border-color:var(--gu-a)}
.gu-ai{border:1.5px solid #ddd6fe;background:#f5f3ff;color:#6d28d9;border-radius:10px;padding:0 11px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.gu-ai:disabled{opacity:.6;cursor:wait}
.gu-r{font-size:12.5px;color:#475569;margin:0 0 8px;line-height:2}
.gu-r[hidden]{display:none}
.gu-cc{display:inline-block;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:14px;padding:0 9px;margin:2px 0 2px 4px;cursor:pointer;font-size:12px;line-height:1.9}
.gu-al{color:#6d28d9;cursor:pointer;font-weight:700;text-decoration:underline;white-space:nowrap}
.gu-h{display:flex;align-items:center;gap:6px;width:100%;border:1.5px solid #e2e8f0;background:#fff;border-radius:10px;padding:8px 11px;font:inherit;font-size:13px;cursor:pointer;color:#334155;text-align:right;margin-bottom:6px}
.gu-h b{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gu-h i{font-style:normal;color:#94a3b8;transition:transform .2s}
.gu.gu-open .gu-h i{transform:rotate(180deg)}
.gu-t{display:none;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:4px;max-height:340px;overflow-y:auto;margin-bottom:8px}
.gu.gu-open .gu-t{display:block}
.gu-n{display:flex;align-items:center;gap:4px;padding:6px 6px;border-radius:8px;cursor:pointer;color:#334155;user-select:none;line-height:1.6}
.gu-n:hover{background:#f1f5f9}
.gu-n.on{background:var(--gu-a);color:#fff}
.gu-n.on .gu-c,.gu-n.on .gu-x{color:rgba(255,255,255,.9)}
.gu-x{width:18px;flex:0 0 18px;text-align:center;color:#94a3b8;font-size:11px;border-radius:5px}
.gu-x:hover{background:rgba(148,163,184,.2)}
.gu-l{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gu-c{font-size:11px;color:#94a3b8}
.gu-k{border-right:1.5px solid #e2e8f0;margin-right:14px;padding-right:2px}
.gu-k[hidden]{display:none}
@media (min-width:900px){.gu.gu-side .gu-h{display:none}.gu.gu-side .gu-t{display:block;max-height:none}}
.gu{--gu-a:var(--c)}
.gl-tabs{display:flex;gap:6px;margin-bottom:10px}.gl-tabs button{border:1.5px solid var(--line);background:#fff;border-radius:10px;padding:6px 14px;font:inherit;font-size:13px;cursor:pointer;color:var(--txt)}.gl-tabs button.on{background:var(--c);border-color:var(--c);color:#fff}
.gl-wrap{display:grid;grid-template-columns:1fr;gap:10px}
@media (min-width:900px){.gl-wrap{grid-template-columns:240px 1fr}}
.gl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;align-content:start}
.gl-it{border:1.5px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;padding:0;cursor:pointer;font:inherit;text-align:right;display:flex;flex-direction:column}
.gl-it:hover{border-color:var(--c)}
.gl-it img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:#f1f5f9}
.gl-it span{padding:5px 8px;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--txt)}
.gl-it.nov img{display:none}.gl-it.nov::before{content:'🎬';display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;font-size:34px;background:#0f172a}
.gl-none{text-align:center;color:var(--mut);padding:20px}.gl-none[hidden]{display:none}
.gl-more{grid-column:1/-1;text-align:center;color:var(--mut);font-size:12px;padding:6px}
.gl-pick{display:flex;gap:12px;align-items:flex-start;margin:10px 0 12px}
.gl-pick img{width:150px;max-width:42%;border-radius:12px;border:1px solid var(--line);background:#f1f5f9}
.gl-pick b{display:block;font-size:15px}.gl-pick p{color:var(--mut);font-size:12.5px;margin-top:4px;white-space:pre-wrap}
.gl-lbl{font-weight:700;font-size:13px;margin:12px 0 6px}
.gl-pr{display:flex;gap:8px;align-items:flex-start;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px;margin-bottom:6px;cursor:pointer;font-size:13px;background:#fff}
.gl-pr input{margin-top:6px}.gl-pr.on{border-color:var(--c);background:color-mix(in srgb,var(--c) 7%,#fff)}
.gl-pr small{display:block;color:var(--mut);font-size:11.5px}
.gl-det textarea{width:100%;border:1.5px solid var(--line);border-radius:12px;padding:9px 11px;font:inherit;font-size:13.5px;min-height:84px;resize:vertical;outline:none;background:#fff;color:var(--txt)}
.gl-det textarea:focus{border-color:var(--c)}
.gl-back{background:none;border:none;color:var(--c);cursor:pointer;font:inherit;font-size:13px;padding:0;font-weight:700}
.gl-go{margin-top:12px;width:100%}
</style>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
<div class="app" id="app"></div>
<script>
(function(){
'use strict';
var C = <?php echo json_encode($js_cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var DOWN = <?php echo json_encode(HDC_DOWN, JSON_UNESCAPED_UNICODE); ?>;
var app = document.getElementById('app');
var S = { name: '', threads: [], folders: [], folder: '', tq: '', tid: 0, busy: false, quota: null, pendingMobile: '', pendingName: '', ext: null, files: [], rec: null, audio: null, via: 0, reply: null, edit: null, gen: '' };

var IC = {
  bot: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="14" r="1.2" fill="currentColor"/><circle cx="15" cy="14" r="1.2" fill="currentColor"/></svg>',
  send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform:scaleX(-1)"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>',
  menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>',
  close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>',
  g: '<svg viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  clip: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
  mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/></svg>',
  plus: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" style="vertical-align:-3px;margin-left:4px"><path d="M12 5v14M5 12h14"/></svg>'
};
function esc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function avatar(){ return '<div class="av">' + (C.avatar ? '<img src="' + esc(C.avatar) + '" alt="">' : IC.bot) + '</div>'; }
function fa(n){ return Number(n).toLocaleString('fa-IR'); }
// GalUI — دسته‌های آبشاری + جستجوی فوری + جستجوی هوشمند (همان کد includes/gal_lib.php)
(function(W){
if (W.GalUI) return;
var FD = '۰۱۲۳۴۵۶۷۸۹', AD = '٠١٢٣٤٥٦٧٨٩';
function nrm(s){
  s = String(s == null ? '' : s).toLowerCase();
  s = s.replace(/[يىئ]/g, 'ی').replace(/ك/g, 'ک').replace(/ة/g, 'ه').replace(/[أإآ]/g, 'ا').replace(/ؤ/g, 'و');
  s = s.replace(/[ً-ٰٟـ]/g, '');
  s = s.replace(/[۰-۹]/g, function(d){ return FD.indexOf(d); }).replace(/[٠-٩]/g, function(d){ return AD.indexOf(d); });
  s = s.replace(/[‌‍‎‏،؛؟٪-٭۔]/g, ' ');
  s = s.replace(/[^0-9a-z؀-ۿ]+/g, ' ');
  return s.replace(/\s+/g, ' ').trim();
}
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function fa(n){ return String(n).replace(/\d/g, function(d){ return FD[d]; }); }
function vars(t){
  var v = [t], suf = ['های', 'ها', 'ای', 'ی', 'ات'];
  for (var i = 0; i < suf.length; i++) if (t.length - suf[i].length >= 3 && t.slice(-suf[i].length) === suf[i]) { v.push(t.slice(0, -suf[i].length)); break; }
  return v;
}
function near1(a, b){
  if (a === b) return true;
  var la = a.length, lb = b.length; if (Math.abs(la - lb) > 1) return false;
  var i = 0, j = 0, e = 0;
  while (i < la && j < lb) {
    if (a[i] === b[j]) { i++; j++; continue; }
    if (++e > 1) return false;
    if (la > lb) i++; else if (lb > la) j++; else { i++; j++; }
  }
  return e + (la - i) + (lb - j) <= 1;
}
function mount(root, cfg){
  cfg = cfg || {};
  var cats = cfg.cats || [], items = cfg.items || [], by = {}, kids = { 0: [] }, sel = 0, mode = 'cat', lastQ = '', timer = 0;
  cats.forEach(function(c){ by[c.id] = { id: c.id, p: c.p, t: c.t, n: c.n }; });
  cats.forEach(function(c){ var p = by[c.p] ? c.p : 0; by[c.id].p = p; (kids[p] = kids[p] || []).push(c.id); });
  function path(id){ var a = [], x = id, g = 0; while (x && by[x] && g++ < 6) { a.unshift(by[x].t); x = by[x].p; } return a.join(' › '); }
  function desc(id){ var o = [id], q = [id]; while (q.length) { var x = q.shift(); (kids[x] || []).forEach(function(k){ o.push(k); q.push(k); }); } return o; }
  function anc(id){ var a = [], x = by[id] ? by[id].p : 0, g = 0; while (x && g++ < 6) { a.push(x); x = by[x] ? by[x].p : 0; } return a; }
  cats.forEach(function(c){ by[c.id].path = path(c.id); by[c.id].np = nrm(by[c.id].path); if (by[c.id].n == null) by[c.id].n = 0; });
  var cnt = {};
  items.forEach(function(it){
    if (!by[it.c]) it.c = 0;
    it._t = nrm(it.t); it._c = it.c ? by[it.c].np : ''; it._x = nrm([it.d, it.g, it.pt, it.p].join(' '));
    it._a = (it._t + ' ' + it._c + ' ' + it._x).trim(); it._m = it._a.replace(/ /g, '');
    it._w = it._a.split(' ').filter(function(w){ return w.length >= 3; });
    cnt[it.c] = (cnt[it.c] || 0) + 1;
  });
  if (cfg.count !== false) cats.forEach(function(c){ var n = 0; desc(c.id).forEach(function(d){ n += cnt[d] || 0; }); by[c.id].n = n; });
  var aiOn = typeof cfg.ai === 'function';
  root.innerHTML = '<div class="gu' + (cfg.side ? ' gu-side' : '') + '">'
    + '<div class="gu-s"><input type="search" class="gu-q" placeholder="' + esc(cfg.ph || '🔍 جستجو در طرح‌ها و دسته‌ها… (مثلاً: لوگو کافه)') + '" autocomplete="off">'
    + (aiOn ? '<button type="button" class="gu-ai" title="' + esc(cfg.aiTip || 'معنای درخواست شما را با هوش مصنوعی پیدا می‌کند') + '">🤖 هوشمند</button>' : '') + '</div>'
    + '<div class="gu-r" hidden></div>'
    + (cats.length ? '<button type="button" class="gu-h"><span>📂</span><b></b><i>▾</i></button><div class="gu-t"></div>' : '')
    + '</div>';
  var G = root.querySelector('.gu'), Q = root.querySelector('.gu-q'), R = root.querySelector('.gu-r'), H = root.querySelector('.gu-h'), T = root.querySelector('.gu-t'), AI = root.querySelector('.gu-ai');
  function nodeHtml(id){
    var c = by[id], ks = kids[id] || [];
    return '<div class="gu-n" data-id="' + id + '"><span class="gu-x">' + (ks.length ? '◂' : '') + '</span><span class="gu-l">' + esc(c.t) + '</span><span class="gu-c">' + fa(c.n) + '</span></div>'
      + (ks.length ? '<div class="gu-k" data-k="' + id + '" hidden>' + ks.map(nodeHtml).join('') + '</div>' : '');
  }
  if (T) T.innerHTML = '<div class="gu-n on" data-id="0"><span class="gu-x"></span><span class="gu-l">' + esc(cfg.all || 'همه طرح‌ها') + '</span><span class="gu-c">' + fa(items.length) + '</span></div>' + (kids[0] || []).map(nodeHtml).join('');
  function box(id){ return T ? T.querySelector('.gu-k[data-k="' + id + '"]') : null; }
  function setOpen(id, on){
    var b = box(id); if (!b) return; b.hidden = !on;
    var n = T.querySelector('.gu-n[data-id="' + id + '"] .gu-x'); if (n) n.textContent = on ? '▾' : '◂';
  }
  function openPath(id){
    // آبشاری: فقط شاخه انتخاب‌شده باز می‌ماند (هم‌سطح‌ها بسته می‌شوند)
    var keep = [id].concat(anc(id));
    Object.keys(kids).forEach(function(k){ k = +k; if (k && keep.indexOf(k) < 0) setOpen(k, false); });
    keep.forEach(function(k){ if (k) setOpen(k, true); });
  }
  function head(){
    if (!H) return;
    H.querySelector('b').textContent = sel ? by[sel].path : (cfg.allHead || 'همه دسته‌ها');
  }
  function mark(){ if (T) Array.prototype.forEach.call(T.querySelectorAll('.gu-n'), function(n){ n.classList.toggle('on', +n.getAttribute('data-id') === sel && mode === 'cat'); }); }
  function emit(ids, meta){ if (cfg.onFilter) cfg.onFilter(ids, meta); }
  function catIds(id){
    if (!id) return null;
    var d = desc(id), o = [];
    items.forEach(function(it){ if (d.indexOf(it.c) >= 0) o.push(it.id); });
    return o;
  }
  function select(id, fromUser){
    sel = by[id] ? id : 0; mode = 'cat'; lastQ = ''; Q.value = ''; R.hidden = true; R.innerHTML = '';
    if (sel) openPath(sel); mark(); head();
    if (fromUser && G.classList.contains('gu-open') && !(kids[sel] || []).length && W.innerWidth < 900) G.classList.remove('gu-open');
    emit(catIds(sel), { mode: 'cat', cat: sel, path: sel ? by[sel].path : '' });
  }
  function toks(q){ return nrm(q).split(' ').filter(function(t){ return t.length >= 2 || /\d/.test(t); }); }
  function scoreTok(it, t){
    var vs = vars(t), best = 0;
    for (var i = 0; i < vs.length; i++) {
      var v = vs[i], w = i ? 0.8 : 1, s = 0;
      if (it._t.indexOf(v) >= 0) s = (' ' + it._t).indexOf(' ' + v) >= 0 ? 4 : 3;
      else if (it._c.indexOf(v) >= 0) s = 2;
      else if (it._x.indexOf(v) >= 0) s = 1;
      else if (v.length >= 3 && it._m.indexOf(v) >= 0) s = 0.8;
      if (s * w > best) best = s * w;
    }
    if (!best && t.length >= 4) for (var j = 0; j < it._w.length; j++) if (near1(it._w[j], t)) { best = 0.6; break; }
    return best;
  }
  function find(q){
    var ts = toks(q), qc = nrm(q).replace(/ /g, '');
    if (!ts.length) return { ids: null, cats: [], partial: false };
    var res = items.map(function(it, ix){
      var s = 0, hit = 0;
      ts.forEach(function(t){ var x = scoreTok(it, t); if (x > 0) { hit++; s += x; } });
      if (qc.length >= 4 && it._m.indexOf(qc) >= 0) { s += 2; hit = Math.max(hit, ts.length); }
      return { id: it.id, s: s, h: hit, ix: ix };
    });
    var all = res.filter(function(r){ return r.h >= ts.length; }), partial = false;
    if (!all.length) { all = res.filter(function(r){ return r.h > 0; }); partial = all.length > 0; }
    all.sort(function(a, b){ return b.h - a.h || b.s - a.s || a.ix - b.ix; });
    var cm = cats.filter(function(c){ var p = by[c.id].np; return ts.every(function(t){ return vars(t).some(function(v){ return p.indexOf(v) >= 0; }); }); }).map(function(c){ return c.id; }).slice(0, 6);
    return { ids: all.map(function(r){ return r.id; }), cats: cm, partial: partial };
  }
  function chips(ids){ return ids.map(function(id){ return by[id] ? '<span class="gu-cc" data-c="' + id + '">📂 ' + esc(by[id].path) + ' (' + fa(by[id].n) + ')</span>' : ''; }).join(''); }
  function bindR(){
    Array.prototype.forEach.call(R.querySelectorAll('.gu-cc'), function(x){ x.onclick = function(){ select(+x.getAttribute('data-c'), true); }; });
    var al = R.querySelector('.gu-al'); if (al) al.onclick = runAI;
  }
  function search(q){
    q = String(q || '').trim(); lastQ = q;
    if (!q) { select(sel, false); return; }
    mode = 'search'; mark(); if (H) H.querySelector('b').textContent = '🔍 جستجو در همه دسته‌ها';
    var f = find(q), n = f.ids.length;
    R.hidden = false;
    R.innerHTML = (n ? '«' + esc(q) + '»: ' + fa(n) + ' ' + esc(cfg.noun || 'طرح') + (f.partial ? ' (نتایج نزدیک)' : '') : (cfg.none || 'طرحی با «' + esc(q) + '» پیدا نشد.'))
      + (f.cats.length ? '<div>' + chips(f.cats) + '</div>' : '')
      + (aiOn ? ' <span class="gu-al">' + (n ? 'نتیجه دلخواه نیست؟ ' : '') + '🤖 جستجوی هوشمند</span>' : '');
    bindR();
    emit(f.ids, { mode: 'search', q: q, partial: f.partial });
  }
  function runAI(){
    var q = Q.value.trim();
    if (q.length < 2) { Q.focus(); Q.placeholder = 'اول بنویسید چه طرحی می‌خواهید…'; return; }
    if (AI) AI.disabled = true;
    R.hidden = false; R.innerHTML = '🤖 در حال جستجوی هوشمند…';
    cfg.ai(q).then(function(r){
      if (AI) AI.disabled = false;
      if (Q.value.trim() !== q) return;
      if (!r || !r.ok) { R.innerHTML = esc((r && r.error) || 'جستجوی هوشمند انجام نشد.'); return; }
      var seen = {}, order = [];
      function add(id){ if (!seen[id]) { seen[id] = 1; order.push(id); } }
      var have = {}; items.forEach(function(it){ have[it.id] = it; });
      (r.ids || []).forEach(function(id){ if (have[id]) add(id); });
      (r.cats || []).forEach(function(c){ if (by[c]) { var d = desc(c); items.forEach(function(it){ if (d.indexOf(it.c) >= 0) add(it.id); }); } });
      (r.kw || []).forEach(function(k){ var f = find(k); (f.ids || []).slice(0, 40).forEach(add); });
      mode = 'ai'; mark(); if (H) H.querySelector('b').textContent = '🔍 جستجو در همه دسته‌ها';
      R.innerHTML = (order.length ? '🤖 نتایج هوشمند برای «' + esc(q) + '»: ' + fa(order.length) + ' ' + esc(cfg.noun || 'طرح') : '🤖 طرح مناسبی برای «' + esc(q) + '» پیدا نشد.')
        + ((r.cats || []).length ? '<div>' + chips(r.cats.filter(function(c){ return by[c]; })) + '</div>' : '');
      bindR();
      emit(order, { mode: 'ai', q: q });
    }, function(){ if (AI) AI.disabled = false; R.innerHTML = 'جستجوی هوشمند انجام نشد.'; });
  }
  Q.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ search(Q.value); }, 160); });
  Q.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(Q.value); } else if (e.key === 'Escape') { Q.value = ''; search(''); } });
  if (AI) AI.onclick = runAI;
  if (H) H.onclick = function(){ G.classList.toggle('gu-open'); };
  if (T) T.addEventListener('click', function(e){
    var n = e.target.closest ? e.target.closest('.gu-n') : null; if (!n) return;
    var id = +n.getAttribute('data-id');
    if (e.target.classList.contains('gu-x') && id && (kids[id] || []).length) { var b = box(id); setOpen(id, b.hidden); return; }
    if (id && id === sel && mode === 'cat' && (kids[id] || []).length) { var b2 = box(id); setOpen(id, b2.hidden); return; }
    select(id, true);
  });
  if (cfg.sel && by[cfg.sel]) select(cfg.sel, false); else head();
  if (cfg.open) G.classList.add('gu-open');
  return { select: function(id){ select(id, false); }, search: function(q){ Q.value = q; search(q); }, path: function(id){ return by[id] ? by[id].path : ''; },
           anc: anc, cat: function(){ return sel; }, find: find, norm: nrm };
}
W.GalUI = { mount: mount, norm: nrm };
})(window);

function api(action, data){
  data = data || {}; data.action = action;
  // صفحه یکپارچه: درخواست برای چت‌بات انتخاب‌شده
  if (data.via === undefined && S.via) data.via = S.via;
  if (!data.via) delete data.via;
  return fetch(C.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'hdc' }, body: JSON.stringify(data) })
    .then(function(r){ return r.json(); })
    .catch(function(){ return { ok: false, error: DOWN }; });
}

// ---------- قالب‌بندی پاسخ (Markdown ساده + ارجاع به منابع) ----------
function inline(t, mid){
  t = esc(t);
  t = t.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
  t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
  t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function(m, a, u){ return '<a href="' + u + '" target="_blank" rel="noopener">' + a + '</a>'; });
  t = t.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, function(m, p, u){ return p + '<a href="' + u + '" target="_blank" rel="noopener">' + u.replace(/^https?:\/\/(www\.)?/, '').slice(0, 40) + '</a>'; });
  t = t.replace(/\[([0-9۰-۹]{1,2})\]/g, function(m, n){
    var k = String(n).replace(/[۰-۹]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
    return '<a class="cite" href="#s' + mid + '-' + k + '">' + k + '</a>';
  });
  return t;
}
function md(text, mid){
  var lines = String(text || '').replace(/\r/g, '').split('\n'), out = [], list = null;
  function close(){ if (list) { out.push('</' + list + '>'); list = null; } }
  for (var i = 0; i < lines.length; i++) {
    var l = lines[i], m;
    if (/^\s*$/.test(l)) { close(); continue; }
    if ((m = l.match(/^\s*#{1,6}\s+(.*)$/))) { close(); out.push('<h4>' + inline(m[1], mid) + '</h4>'); continue; }
    if (/^\s*\|.*\|\s*$/.test(l)) { // جدول
      close(); var rows = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { if (!/^\s*\|[\s:\-|]+\|\s*$/.test(lines[i])) rows.push(lines[i]); i++; }
      i--;
      out.push('<table>' + rows.map(function(r, ri){ var cells = r.trim().replace(/^\||\|$/g, '').split('|'); return '<tr>' + cells.map(function(c){ return (ri ? '<td>' : '<th>') + inline(c.trim(), mid) + (ri ? '</td>' : '</th>'); }).join('') + '</tr>'; }).join('') + '</table>');
      continue;
    }
    if ((m = l.match(/^\s*[-*•]\s+(.*)$/))) { if (list !== 'ul') { close(); out.push('<ul>'); list = 'ul'; } out.push('<li>' + inline(m[1], mid) + '</li>'); continue; }
    if ((m = l.match(/^\s*[0-9۰-۹]+[.)]\s+(.*)$/))) { if (list !== 'ol') { close(); out.push('<ol>'); list = 'ol'; } out.push('<li>' + inline(m[1], mid) + '</li>'); continue; }
    close(); out.push('<p>' + inline(l, mid) + '</p>');
  }
  close();
  return out.join('');
}
function sourcesHtml(src, mid){
  if (!src || !src.length) return '';
  return '<div class="srcs"><b>منابع:</b>' + src.map(function(s){
    var dom = s.url ? s.url.replace(/^https?:\/\/(www\.)?/, '').split('/')[0] : '';
    var t = esc(s.title || ('منبع ' + s.n));
    return '<div class="src" id="s' + mid + '-' + s.n + '"><span class="cite">' + s.n + '</span>' + (s.url ? '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + t + '</a> <em>' + esc(dom) + '</em>' : '<span>' + t + '</span>') + '</div>';
  }).join('') + '</div>';
}

// ---------- صفحه ورود ----------
// ---------- ورود / ثبت‌نام ----------
// step: form (نام و موبایل) | choose (حساب موجود: انتخاب روش) | code (کد پیامکی ورود) | reset (فراموشی رمز) | gm (تکمیل ثبت‌نام با گوگل)
function viewLogin(step, err, info){
  step = step || 'form';
  var intro = C.specialty ? esc(C.specialty) : 'برای شروع گفتگو وارد شوید';
  var h = '<div class="main">' + (C.embed && !C.noclose ? '<div style="display:flex;justify-content:flex-start;padding:10px"><button class="hbtn" id="cls" style="display:flex;background:#e2e8f0;color:#475569" title="بستن">' + IC.close + '</button></div>' : '') + '<div class="login"><div class="lbox">' + avatar() + '<h2>' + esc(C.name) + '</h2>';
  var M = S.methods || {};
  var ORD = (C.order && C.order.length) ? C.order : ['sms', 'google', 'password'];
  if (step === 'form') {
    var gFirst = C.google && ORD[0] === 'google';
    h += '<p>' + intro + '</p><div class="err" id="err"></div>'
      + (gFirst ? '<button class="btn btn-c" id="gbtn">' + IC.g + 'ورود با حساب گوگل</button><div class="lsep">یا با شماره موبایل</div>' : '')
      + '<input id="nm" placeholder="نام شما" maxlength="60" value="' + esc(S.pendingName) + '">'
      + '<input id="mb" placeholder="شماره موبایل" inputmode="tel" dir="ltr" maxlength="14" value="' + esc(S.pendingMobile) + '">'
      + '<button class="btn ' + (gFirst ? 'btn-o' : 'btn-c') + '" id="go">ادامه</button>'
      + '<p style="font-size:11.5px;margin:8px 0 0">اگر قبلاً حساب دارید، فقط شماره موبایل کافی است.</p>'
      + (C.google && !gFirst ? '<div class="lsep">یا</div><button class="btn btn-o" id="gbtn">' + IC.g + 'ورود با حساب گوگل</button>' : '');
  } else if (step === 'choose') {
    h += '<p>حساب ' + esc(S.masked || S.pendingMobile) + ' پیدا شد. برای ورود یکی از روش‌ها را انتخاب کنید.</p><div class="err" id="err"></div>';
    var mo = (M.order && M.order.length) ? M.order.slice() : ORD.slice();
    ['sms', 'google', 'password'].forEach(function(k){ if (mo.indexOf(k) < 0) mo.push(k); });
    var first = true;
    mo.forEach(function(k){
      if (!M[k]) return;
      var cls = first ? 'btn-c' : 'btn-o';
      if (!first) h += '<div class="lsep">یا</div>';
      if (k === 'password') h += '<input id="pw" type="password" placeholder="رمز عبور" autocomplete="current-password" dir="ltr">'
        + '<div class="cap"><img id="capimg" alt="کد امنیتی"><input id="cap" inputmode="numeric" maxlength="6" placeholder="عدد تصویر" dir="ltr"><button type="button" id="caprf" title="تصویر جدید">↻</button></div>'
        + '<button class="btn ' + cls + '" id="go">ورود با رمز</button>'
        + (C.sms ? '<p style="margin:6px 0 0"><button class="link" id="forgot">رمز را فراموش کرده‌ام</button></p>' : '');
      else if (k === 'sms') h += '<button class="btn ' + cls + '" id="smsb">📩 ارسال کد ورود با پیامک</button>';
      else if (k === 'google') h += '<button class="btn ' + cls + '" id="gbtn">' + IC.g + 'ورود با حساب گوگل</button>';
      first = false;
    });
    h += '<p style="margin:12px 0 0"><button class="link" id="back">تغییر شماره</button></p>';
  } else if (step === 'code') {
    h += '<div class="err" id="err"></div><p style="margin-bottom:10px">کد ۵ رقمی پیامک‌شده به ' + esc(S.pendingMobile) + ' را وارد کنید.</p>'
      + '<input id="code" inputmode="numeric" maxlength="5" placeholder="کد تأیید" dir="ltr" autocomplete="one-time-code">'
      + '<button class="btn btn-c" id="go">ورود</button>'
      + '<p style="margin-top:12px"><button class="link" id="back">تغییر شماره</button></p>';
  } else if (step === 'reset') {
    h += '<div class="err" id="err"></div><p style="margin-bottom:10px">کد بازیابی به ' + esc(S.masked || S.pendingMobile) + ' پیامک شد. کد و رمز جدید را وارد کنید.</p>'
      + '<input id="code" inputmode="numeric" maxlength="5" placeholder="کد پیامک‌شده" dir="ltr" autocomplete="one-time-code">'
      + '<input id="pw1" type="password" placeholder="رمز جدید (حداقل ۶ کاراکتر)" autocomplete="new-password" dir="ltr">'
      + '<input id="pw2" type="password" placeholder="تکرار رمز جدید" autocomplete="new-password" dir="ltr">'
      + '<button class="btn btn-c" id="go">ذخیره رمز و ورود</button>'
      + '<p style="margin-top:12px"><button class="link" id="back">بازگشت</button></p>';
  } else if (step === 'gm') {
    h += '<p>حساب گوگل ' + esc(S.gEmail || '') + ' تأیید شد. برای ساخت حساب، نام و شماره موبایل خود را وارد کنید.</p><div class="err" id="err"></div>'
      + '<input id="nm" placeholder="نام شما" maxlength="60" value="' + esc(S.gName || S.pendingName) + '">'
      + '<input id="mb" placeholder="شماره موبایل" inputmode="tel" dir="ltr" maxlength="14" value="' + esc(S.pendingMobile) + '">'
      + '<button class="btn btn-c" id="go">ساخت حساب و ورود</button>'
      + '<p style="margin-top:12px"><button class="link" id="back">انصراف</button></p>';
  }
  h += '</div></div></div>';
  app.innerHTML = h;
  var cl = document.getElementById('cls'); if (cl) cl.onclick = function(){ parent.postMessage('hdc-close', '*'); };
  var e = document.getElementById('err');
  if (err) { e.textContent = err; e.style.display = 'block'; }
  if (info) { var ok = document.createElement('div'); ok.className = 'okm'; ok.textContent = info; e.parentNode.insertBefore(ok, e); }
  var go = document.getElementById('go');
  function fail(msg){ e.textContent = msg || DOWN; e.style.display = 'block'; if (go) go.disabled = false; }
  var back = document.getElementById('back'); if (back) back.onclick = function(){ viewLogin('form'); };
  var gb = document.getElementById('gbtn'); if (gb) gb.onclick = function(){ googleGo('g_start', fail); };
  function enter(el, fn){ if (el) el.onkeydown = function(ev){ if (ev.key === 'Enter') fn(); }; }

  if (step === 'form') {
    var submit = function(){
      S.pendingName = document.getElementById('nm').value.trim();
      S.pendingMobile = document.getElementById('mb').value.trim();
      go.disabled = true;
      api('login', { name: S.pendingName, mobile: S.pendingMobile }).then(function(r){
        if (!r.ok) return fail(r.error);
        if (r.need_code) return viewLogin('code');
        if (r.exists) { S.methods = r.methods || {}; S.masked = r.mobile_masked || ''; return viewLogin('choose'); }
        S.secureHint = !!r.secure_hint; start();
      });
    };
    go.onclick = submit; enter(document.getElementById('mb'), submit); enter(document.getElementById('nm'), submit);
  } else if (step === 'choose') {
    var capId = '';
    function loadCap(){ var im = document.getElementById('capimg'); if (!im) return; api('captcha').then(function(r){ if (r.ok) { capId = r.id; im.src = r.img; document.getElementById('cap').value = ''; } }); }
    if (M.password) {
      loadCap(); document.getElementById('caprf').onclick = loadCap;
      var pwGo = function(){
        go.disabled = true;
        api('pw_login', { mobile: S.pendingMobile, password: document.getElementById('pw').value, captcha_id: capId, captcha: document.getElementById('cap').value })
          .then(function(r){ if (r.ok) { start(); return; } loadCap(); fail(r.error); });
      };
      go.onclick = pwGo; enter(document.getElementById('cap'), pwGo); enter(document.getElementById('pw'), function(){ document.getElementById('cap').focus(); });
      if (mo[0] === 'password' || !M.sms) document.getElementById('pw').focus();
    }
    var sb = document.getElementById('smsb');
    if (sb) sb.onclick = function(){ sb.disabled = true; api('send_code', { mobile: S.pendingMobile, purpose: 'login' }).then(function(r){ sb.disabled = false; if (r.ok) viewLogin('code'); else fail(r.error); }); };
    var fg = document.getElementById('forgot');
    if (fg) fg.onclick = function(){ fg.disabled = true; api('send_code', { mobile: S.pendingMobile, purpose: 'reset' }).then(function(r){ fg.disabled = false; if (r.ok) viewLogin('reset'); else fail(r.error); }); };
  } else if (step === 'code') {
    var ci = document.getElementById('code'); ci.focus();
    var submitCode = function(){ go.disabled = true; api('verify', { name: S.pendingName, mobile: S.pendingMobile, code: ci.value }).then(function(r){ if (r.ok) start(); else fail(r.error); }); };
    go.onclick = submitCode; enter(ci, submitCode);
  } else if (step === 'reset') {
    document.getElementById('back').onclick = function(){ viewLogin('choose'); };
    document.getElementById('code').focus();
    var rs = function(){
      var p1 = document.getElementById('pw1').value, p2 = document.getElementById('pw2').value;
      if (p1 !== p2) return fail('تکرار رمز با رمز جدید یکسان نیست.');
      go.disabled = true;
      api('pw_reset', { mobile: S.pendingMobile, code: document.getElementById('code').value, password: p1 }).then(function(r){ if (r.ok) start(); else fail(r.error); });
    };
    go.onclick = rs; enter(document.getElementById('pw2'), rs);
  } else if (step === 'gm') {
    document.getElementById('back').onclick = function(){ S.gCode = ''; viewLogin('form'); };
    var gs = function(){
      S.pendingName = document.getElementById('nm').value.trim(); S.pendingMobile = document.getElementById('mb').value.trim();
      go.disabled = true;
      api('g_register', { code: S.gCode, name: S.pendingName, mobile: S.pendingMobile }).then(function(r){ if (r.ok) { S.gCode = ''; start(); } else fail(r.error); });
    };
    go.onclick = gs; enter(document.getElementById('mb'), gs);
  }
}

// ورود / اتصال گوگل: در پنجره جدا (چون صفحه گوگل داخل قاب باز نمی‌شود) یا همین صفحه
function googleGo(action, onErr){
  var w = null;
  if (C.embed) { try { w = window.open('about:blank', 'hdc_google', 'width=520,height=640'); } catch (x) { w = null; } }
  api(action).then(function(r){
    if (!r.ok || !r.url) { if (w) w.close(); (onErr || alert)(r.error || DOWN); return; }
    if (w && !w.closed) w.location.href = r.url; else window.location.href = r.url;
  });
}
function gResult(res, extra){
  if (res === 'ok') { start(); return; }
  if (res === 'linked') { start(); setTimeout(showProfile, 400); return; }
  if (res === 'mobile') { S.gCode = extra; viewLogin('gm'); return; }
  if (res === 'cancel') { start(); return; }
  S.gErr = extra || 'ورود با گوگل انجام نشد؛ دوباره تلاش کنید.';
  start();
}
window.addEventListener('message', function(ev){
  if (ev.origin !== location.origin || !ev.data || ev.data.t !== 'hdc-g') return;
  gResult(ev.data.r, ev.data.x);
});

// ---------- صفحه گفتگو ----------
function viewChat(){
  var e = X();
  app.innerHTML =
    '<div class="overlay" id="ov"></div>'
  + '<aside class="side" id="side"><div class="side-top"><button class="btn btn-c" id="new">' + IC.plus + 'گفتگوی جدید</button>'
  + '<input type="search" id="tsq" class="tsq" placeholder="🔎 جستجو در گفتگوها…"><div class="fchips" id="fchips"></div></div>'
  + '<div class="threads" id="threads"></div>'
  + (C.support ? '<div class="sup">' + esc(C.support) + '</div>' : '')
  + '<div class="side-links">'
  + (C.hub ? '<button class="link" id="hubb">🤖 همه چت‌بات‌ها</button>' : '')
  + (C.canBuy ? '<button class="link" id="plb">⭐ بسته‌ها و ارتقا</button>' : '')
  + (e.support ? '<button class="link" id="supb">🎧 پشتیبانی و راهنما' + (e.unread_tickets ? ' <span class="sbadge">' + fa(e.unread_tickets) + '</span>' : '') + '</button>' : '')
  + (C.rem && !e.imp ? '<button class="link" id="remb">⏰ یادآورها و برنامه‌ها <span class="sbadge" id="remn" style="display:none"></span></button>' : '')
  + '</div>'
  + '<div class="side-foot"><button class="link" id="prof" title="پرونده من">📋 ' + esc(S.name || 'پرونده من') + '</button><button class="link" id="out">خروج</button></div></aside>'
  + '<div class="main" id="mainp"><div class="head"><button class="hbtn" id="menu" title="گفتگوها">' + IC.menu + '</button>' + avatar()
  + '<div class="sp"><h1>' + esc(C.name) + '</h1><small>' + esc(C.specialty || 'آنلاین') + '</small></div>'
  + (C.hub ? '<button class="hbtn pf" id="hub2" title="همه چت‌بات‌ها">🤖<span>چت‌بات‌ها</span></button>' : '')
  + (C.canBuy && !e.imp ? '<button class="hbtn pf up" id="up2" title="بسته‌ها و ارتقا">⭐<span>ارتقا</span></button>' : '')
  + '<button class="hbtn pf" id="prof2" title="پرونده من و اجازه دسترسی همکاران">' + IC.user + '<span>پرونده من</span></button>'
  + (C.embed && !C.noclose ? '<button class="hbtn" id="cls" style="display:flex" title="بستن">' + IC.close + '</button>' : '') + '</div>'
  + (C.disclaimer ? '<div class="disc">⚠️ ' + esc(C.disclaimer) + '</div>' : '')
  + '<div id="cbar"></div>'
  + '<div class="msgs" id="msgs"><div class="wrap" id="wrap"></div><div class="dropz" id="dropz"><div>📥 فایل یا تصویر را اینجا رها کنید</div></div></div>'
  + '<div class="foot"><div id="fnote"></div><div id="limbar"></div><div class="ctxbar" id="ctxbar"></div><div class="atts" id="atts"></div><div class="recbar" id="recbar"></div>'
  + '<div class="comp">'
  + ((e.files || e.files_lock) ? '<button class="ibtn' + (e.files ? '' : ' lk') + '" id="att" title="' + (e.files ? 'افزودن تصویر یا فایل (یا بکشید و رها کنید)' : 'ارسال تصویر و فایل — با ارتقای بسته') + '">' + IC.clip + '</button><input type="file" id="fin" multiple accept="image/*,.pdf,.docx,.txt" style="display:none">' : '')
  + ((e.voice || e.voice_lock) ? '<button class="ibtn' + (e.voice ? '' : ' lk') + '" id="mic" title="' + (e.voice ? 'پیام صوتی' : 'پیام صوتی — با ارتقای بسته') + '">' + IC.mic + '</button>' : '')
  + ((e.gen_image || e.gen_video) ? '<div class="mdsel"><button class="ibtn" id="genb" type="button" title="ساخت تصویر و ویدیو">✨</button><div class="mdlist" id="genl"></div></div>' : '')
  + (C.rem && !e.imp ? '<button class="ibtn" id="remc" type="button" title="ثبت یادآور (بنویسید چه چیزی و کی)">⏰</button>' : '')
  + '<textarea id="inp" rows="1" placeholder="سوال خود را بنویسید…" maxlength="3000"></textarea>'
  + ((e.models && e.models.length) || (e.locked_models && e.locked_models.length) ? '<div class="mdsel"><button class="mdbtn" id="mdb" type="button" title="انتخاب مدل"></button><div class="mdlist" id="mdl"></div></div>' : '')
  + '<div class="sendw" id="sendw"><span class="qring" id="qring" style="display:none" role="img" aria-label="وضعیت اعتبار اشتراک"></span><button class="send" id="snd" title="ارسال">' + IC.send + '</button></div></div><div class="quota" id="quota"></div></div></div>';

  var side = document.getElementById('side'), ov = document.getElementById('ov');
  function toggle(o){ side.classList.toggle('open', o); ov.classList.toggle('open', o); }
  S.toggleSide = toggle;
  document.getElementById('menu').onclick = function(){ toggle(!side.classList.contains('open')); };
  ov.onclick = function(){ toggle(false); };
  document.getElementById('new').onclick = function(){ S.tid = 0; clearCtx(); renderThreads(); showWelcome(); toggle(false); document.getElementById('inp').focus(); };
  document.getElementById('out').onclick = function(){
    if (!confirm('از حساب خود خارج می‌شوید؟')) return;
    api('logout').then(function(){ return S.via ? api('logout', { via: 0 }) : null; }).then(function(){
      S.pf = null;
      if (S.via) { S.via = 0; try { sessionStorage.removeItem('hdc_via'); } catch (x) {} restoreHome(); }
      viewLogin('form');
    });
  };
  var cls = document.getElementById('cls'); if (cls) cls.onclick = function(){ parent.postMessage('hdc-close', '*'); };
  var inp = document.getElementById('inp'), snd = document.getElementById('snd');
  inp.oninput = function(){ inp.style.height = 'auto'; inp.style.height = Math.min(inp.scrollHeight, 160) + 'px'; cancelAutoSend(); };
  inp.onkeydown = function(ev){ if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); send(); } };
  inp.onfocus = cancelAutoSend;
  inp.addEventListener('paste', function(ev){
    var items = (ev.clipboardData && ev.clipboardData.files) ? Array.prototype.slice.call(ev.clipboardData.files) : [];
    if (items.length) { ev.preventDefault(); addFiles(items); }
  });
  snd.onclick = send;
  document.getElementById('prof').onclick = showProfile;
  document.getElementById('prof2').onclick = showProfile;
  var tsq = document.getElementById('tsq'); tsq.oninput = function(){ S.tq = tsq.value.trim().toLowerCase(); renderThreads(); };
  ['hubb', 'hub2'].forEach(function(k){ var b = document.getElementById(k); if (b) b.onclick = function(){ toggle(false); showHub(); }; });
  ['plb', 'up2'].forEach(function(k){ var b = document.getElementById(k); if (b) b.onclick = function(){ toggle(false); showPlans(); }; });
  renderConsentBar();
  var supb = document.getElementById('supb'); if (supb) supb.onclick = function(){ toggle(false); showSupport(); };
  var remb = document.getElementById('remb'); if (remb) remb.onclick = function(){ toggle(false); showRem(); };
  var remc = document.getElementById('remc'); if (remc) remc.onclick = function(){ if (S.remMode) { clearCtx(); } else { setCtx('rem', true); } document.getElementById('inp').focus(); };
  remStart();
  if (C.openPlans && C.canBuy && !X().imp) { C.openPlans = false; setTimeout(function(){ showPlans(); }, 300); }
  renderNotice(); showPopup();
  setupAttach(); setupDrop(); setupMic(); setupGen(); renderModel();
  showWelcome(); renderQuota(); loadThreads();
  if (C.payMsg) {
    var bn = document.createElement('div'); bn.className = 'bnr ' + (C.payOk ? 'ok' : 'no'); bn.textContent = (C.payOk ? '✅ ' : '⚠️ ') + C.payMsg;
    var ms = document.getElementById('msgs'); ms.insertBefore(bn, ms.firstChild); C.payMsg = '';
    try { history.replaceState(null, '', location.pathname); } catch (x) {}
  }
  // پیش‌بارگذاری «پرونده من» برای باز شدن سریع
  setTimeout(function(){ if (!S.pf && !S.pfLoading) { S.pfLoading = true; api('profile').then(function(r){ S.pfLoading = false; if (r.ok) S.pf = r; }); } }, 1500);
}

// ---------- صفحه‌های کامل (پشتیبانی، بسته‌ها، چت‌بات‌ها) با امکان بازگشت ----------
var FV = [];
function openView(title){
  var v = document.createElement('div'); v.className = 'fv';
  v.innerHTML = '<div class="fvh"><button class="fvb" type="button">→ بازگشت به گفتگو</button><b>' + esc(title) + '</b></div><div class="fvc"><div class="fvi"></div></div>';
  document.body.appendChild(v); FV.push(v);
  try { history.pushState({ hdcv: FV.length }, ''); } catch (x) {}
  v.querySelector('.fvb').onclick = function(){ closeView(true); };
  return v.querySelector('.fvi');
}
function closeView(fromBtn){
  var v = FV.pop(); if (v) v.remove();
  if (fromBtn) { S.skipPop = true; try { history.back(); } catch (x) { S.skipPop = false; } }
}
function closeAllViews(){ while (FV.length) { var v = FV.pop(); v.remove(); } }
window.addEventListener('popstate', function(){
  if (S.skipPop) { S.skipPop = false; return; }
  if (FV.length) { var v = FV.pop(); v.remove(); }
});
function loadingHtml(){ return '<div style="text-align:center;padding:30px"><span class="typing"><span></span><span></span><span></span></span></div>'; }

// ---------- اعلان، پاپ‌آپ، تبلیغ، پیشنهاد مدل ----------
function exImg(u){ return /^exi:/.test(u || '') ? '?a=exi&f=' + encodeURIComponent(u.slice(4)) : (/^https?:\/\//i.test(u || '') ? u : ''); }
function renderNotice(){
  var el = document.getElementById('fnote'); if (!el) return;
  var t = X().notice || ''; el.innerHTML = '';
  if (!t) return;
  var key = 'hdn_' + t.length + '_' + t.slice(0, 20);
  try { if (sessionStorage.getItem(key)) return; } catch (x) {}
  var d = document.createElement('div'); d.className = 'fnote'; d.textContent = t;
  var b = document.createElement('button'); b.type = 'button'; b.title = 'بستن'; b.textContent = '×';
  b.onclick = function(){ d.remove(); try { sessionStorage.setItem(key, '1'); } catch (x) {} };
  d.appendChild(b); el.appendChild(d);
}
function showPopup(){
  var P = X().popups || [];
  function K(p){ return 'hdp_' + p.id + '_' + p.v; }
  function ok(p){ try { var v = localStorage.getItem(K(p)); if (p.freq === 'once' && v) return false; if (p.freq === 'daily' && v && (Date.now() - (+v)) < 86400000) return false; if (p.freq === 'session' && sessionStorage.getItem(K(p))) return false; } catch (x) {} return true; }
  var p = null; for (var i = 0; i < P.length; i++) if (ok(P[i])) { p = P[i]; break; }
  if (!p) return;
  setTimeout(function(){
    try { localStorage.setItem(K(p), String(Date.now())); sessionStorage.setItem(K(p), '1'); } catch (x) {}
    api('popup_hit', { value: p.id, title: 'view' });
    var m = document.createElement('div'); m.className = 'mdl'; var img = exImg(p.image), u = /^https?:\/\//i.test(p.url || '') ? p.url : '';
    m.innerHTML = '<div class="mbox" style="padding:0;overflow:hidden">' + (img ? '<img src="' + esc(img) + '" alt="" style="width:100%;max-height:240px;object-fit:cover;display:block">' : '')
      + '<div style="padding:16px 18px"><h3 style="margin-bottom:6px">' + esc(p.title || '') + ' <button type="button" title="بستن">×</button></h3>'
      + (p.body ? '<div style="white-space:pre-wrap;font-size:13.5px;line-height:2;color:#475569">' + esc(p.body) + '</div>' : '')
      + (p.btn ? (u ? '<a class="btn btn-c" style="display:block;text-align:center;margin-top:12px;text-decoration:none" href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(p.btn) + '</a>' : '<button type="button" class="btn btn-c" style="margin-top:12px">' + esc(p.btn) + '</button>') : '') + '</div></div>';
    document.body.appendChild(m);
    function close(){ m.remove(); }
    m.onclick = function(ev){ if (ev.target === m) close(); };
    m.querySelector('h3 button').onclick = close;
    var b = m.querySelector('.btn-c'); if (b) b.addEventListener('click', function(){ api('popup_hit', { value: p.id, title: 'click' }); setTimeout(close, 50); });
  }, (p.delay || 0) * 1000);
}
function addAd(ad){
  var w = document.getElementById('wrap'); if (!w || !ad) return;
  var img = exImg(ad.image), u = /^https?:\/\//i.test(ad.url || '') ? ad.url : '';
  var d = document.createElement('div'); d.className = 'adcard';
  d.innerHTML = (img ? '<img src="' + esc(img) + '" alt="">' : '') + '<div class="ac"><span class="tag">آگهی</span>' + (ad.title ? '<div class="at">' + esc(ad.title) + '</div>' : '') + (ad.body ? '<div class="ab">' + esc(ad.body) + '</div>' : '')
    + (u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(ad.btn || 'مشاهده') + '</a>' : '') + '</div>';
  var a = d.querySelector('a'); if (a) a.onclick = function(){ api('ad_click', { ad_id: ad.id }); };
  w.appendChild(d); scrollDown();
}
function addSuggest(sg){
  var w = document.getElementById('wrap'); if (!w || !sg) return;
  var d = document.createElement('div'); d.className = 'sugg';
  d.innerHTML = '<span>💡 ' + esc(sg.text) + '</span>';
  var b = document.createElement('button'); b.type = 'button';
  if (sg.kind === 'model') { b.textContent = 'استفاده از «' + sg.title + '»'; b.onclick = function(){ if (S.ext) { S.ext.model = sg.id; renderModel(); } api('model', { model_id: sg.id }); d.innerHTML = '<span>✓ مدل «' + esc(sg.title) + '» برای پیام‌های بعدی انتخاب شد.</span>'; }; }
  else { b.textContent = '⭐ مشاهده بسته‌ها'; b.onclick = function(){ showPlans(); }; if (!C.canBuy) b = null; }
  if (b) d.appendChild(b);
  w.appendChild(d); scrollDown();
}

// ---------- پیشنهاد ارتقا (امکان قفل) ----------
var PROMO_SVG = '<svg viewBox="0 0 320 170" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs><linearGradient id="pg1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7c3aed"/><stop offset=".55" stop-color="#db2777"/><stop offset="1" stop-color="#f59e0b"/></linearGradient><radialGradient id="pg2" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#fff" stop-opacity=".9"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient></defs>'
  + '<rect width="320" height="170" rx="18" fill="url(#pg1)"/><circle cx="250" cy="40" r="46" fill="url(#pg2)" opacity=".35"/><circle cx="60" cy="140" r="60" fill="url(#pg2)" opacity=".2"/>'
  + '<g fill="#fff"><path d="M40 30l3 8 8 3-8 3-3 8-3-8-8-3 8-3z" opacity=".9"/><path d="M278 118l2 6 6 2-6 2-2 6-2-6-6-2 6-2z" opacity=".85"/><path d="M92 112l2 5 5 2-5 2-2 5-2-5-5-2 5-2z" opacity=".7"/><circle cx="220" cy="24" r="2.5"/><circle cx="120" cy="22" r="2"/><circle cx="290" cy="70" r="2"/></g>'
  + '<g transform="translate(160 92) rotate(-35)"><path d="M0-58c14 10 22 30 22 52v22h-44v-22c0-22 8-42 22-52z" fill="#fff"/><circle cx="0" cy="-22" r="9" fill="#7c3aed"/><circle cx="0" cy="-22" r="4.5" fill="#bfdbfe"/><path d="M-22 4l-16 20 16-4zM22 4l16 20-16-4z" fill="#fde68a"/><path d="M-12 18h24l-5 24h-14z" fill="#fb923c"/><path d="M-6 22h12l-4 30h-4z" fill="#fde047"/></g></svg>';
function showPromo(feature){
  var titles = { model: 'مدل‌های تخصصی و قوی‌تر', files: 'ارسال تصویر و فایل', voice: 'پیام صوتی و شنیدن پاسخ‌ها', image: 'ساخت تصویر با هوش مصنوعی', video: 'ساخت ویدیو با هوش مصنوعی' };
  var t = titles[feature] || titles.model;
  var m = document.createElement('div'); m.className = 'mdl';
  m.innerHTML = '<div class="mbox promo"><div class="pimg">' + PROMO_SVG + '</div><h3 style="justify-content:center">🚀 ' + esc(t) + ' <button type="button" title="بستن" style="position:absolute;left:14px;top:12px">×</button></h3>'
    + '<p>' + esc(X().upgrade_text || ('«' + t + '» را با ارتقای سطح بسته خود می‌توانید داشته باشید؛ پاسخ‌های دقیق‌تر، امکانات بیشتر و تجربه‌ای کامل‌تر در انتظار شماست ✨')) + '</p>'
    + (C.canBuy ? '<button type="button" class="btn btn-c" id="prgo">⭐ مشاهده بسته‌ها و ارتقا</button>' : '<p style="font-size:12px;color:var(--mut)">' + esc(C.support || 'برای ارتقا با پشتیبانی تماس بگیرید.') + '</p>') + '</div>';
  document.body.appendChild(m);
  function close(){ m.remove(); }
  m.onclick = function(ev){ if (ev.target === m) close(); };
  m.querySelector('h3 button').onclick = close;
  var g = m.querySelector('#prgo'); if (g) g.onclick = function(){ close(); showPlans(); };
}

// ---------- پشتیبانی (صفحه کامل با امکان بازگشت به گفتگو) ----------
function showSupport(){
  var box0 = openView('🎧 پشتیبانی و راهنما');
  box0.innerHTML = '<div class="stabs"><button type="button" data-t="ask" class="on">🤖 پرسش</button><button type="button" data-t="tk">🎫 تیکت‌ها' + (X().unread_tickets ? ' <span class="sbadge">' + fa(X().unread_tickets) + '</span>' : '') + '</button><button type="button" data-t="guide">📘 راهنما</button><button type="button" data-t="faq">❓ سوالات متداول</button></div><div id="spb" class="card"></div>';
  var m = box0;
  var box = m.querySelector('#spb'), help = null, hist = [];
  function loading(){ box.innerHTML = loadingHtml(); }
  function tab(t){
    Array.prototype.forEach.call(m.querySelectorAll('.stabs button'), function(b){ b.classList.toggle('on', b.getAttribute('data-t') === t); });
    if (t === 'ask') return ask();
    if (t === 'tk') return tickets();
    loading();
    (help ? Promise.resolve(help) : api('help')).then(function(r){
      help = r; var list = (r.ok ? (t === 'faq' ? r.faq : r.guide) : []) || [];
      box.innerHTML = list.length ? '<input type="search" id="sqq" placeholder="🔎 جستجو…" class="inpt" style="margin-bottom:10px">' + list.map(function(a){ return '<details class="sq"><summary>' + esc(a.title) + '</summary><div>' + esc(a.body) + '</div></details>'; }).join('')
        : '<p style="text-align:center;color:var(--mut);padding:16px">موردی ثبت نشده است.</p>';
      var q = box.querySelector('#sqq'); if (q) q.oninput = function(){ var v = q.value.trim().toLowerCase(); Array.prototype.forEach.call(box.querySelectorAll('.sq'), function(d){ d.style.display = !v || d.textContent.toLowerCase().indexOf(v) >= 0 ? '' : 'none'; }); };
    });
  }
  function ask(){
    box.innerHTML = '<div id="saw" style="max-height:52vh;overflow-y:auto;margin-bottom:8px"><div class="tkm them">سلام! سوال خود درباره استفاده از این سرویس، بسته‌ها یا پرداخت را بپرسید.</div></div>'
      + '<div style="display:flex;gap:6px"><textarea id="saq" rows="2" maxlength="1500" class="inpt" style="flex:1" placeholder="سوال شما…"></textarea><button type="button" class="btn btn-c" id="sab" style="width:auto;padding:0 16px">ارسال</button></div>'
      + '<button type="button" class="link" id="satk" style="margin-top:8px;font-size:12.5px">پاسخ کافی نبود؟ ثبت تیکت</button>';
    var saw = box.querySelector('#saw'), q = box.querySelector('#saq'), b = box.querySelector('#sab');
    box.querySelector('#satk').onclick = function(){ newTicket(hist); };
    function add(me, t){ var d = document.createElement('div'); d.className = 'tkm ' + (me ? 'me' : 'them'); d.textContent = t; saw.appendChild(d); saw.scrollTop = saw.scrollHeight; return d; }
    function go(){
      var t = q.value.trim(); if (!t || b.disabled) return; q.value = ''; add(true, t); var w = add(false, '…'); b.disabled = true;
      api('support_ask', { message: t, value: JSON.stringify(hist) }).then(function(r){
        w.textContent = r.ok ? r.reply : (r.error || DOWN);
        hist.push({ role: 'user', content: t }); if (r.ok) hist.push({ role: 'assistant', content: r.reply });
        if (r.ticket) { var x = document.createElement('button'); x.type = 'button'; x.className = 'link'; x.style.cssText = 'display:block;margin-top:6px;font-size:12.5px'; x.textContent = '🎫 ثبت تیکت با همین گفتگو'; x.onclick = function(){ newTicket(hist); }; w.appendChild(x); }
        b.disabled = false; q.focus();
      });
    }
    b.onclick = go; q.onkeydown = function(ev){ if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); go(); } };
  }
  function newTicket(h){
    Array.prototype.forEach.call(m.querySelectorAll('.stabs button'), function(x){ x.classList.toggle('on', x.getAttribute('data-t') === 'tk'); });
    var uq = (h || []).filter(function(x){ return x.role === 'user'; }), first = (uq[uq.length - 1] || {}).content || '';
    box.innerHTML = '<div class="pf-h">➕ تیکت جدید</div><input id="tks" maxlength="200" placeholder="موضوع" class="inpt" style="margin-bottom:8px">'
      + '<textarea id="tkb" rows="6" maxlength="6000" placeholder="شرح درخواست" class="inpt"></textarea>'
      + '<button type="button" class="btn btn-c" id="tkg" style="margin-top:8px">📨 ارسال تیکت</button><div id="tkm" style="font-size:12px;margin-top:6px"></div>';
    box.querySelector('#tks').value = first.slice(0, 120);
    if (h && h.length) box.querySelector('#tkb').value = h.map(function(x){ return (x.role === 'user' ? 'من: ' : 'پشتیبان هوشمند: ') + x.content; }).join('\n\n');
    box.querySelector('#tkg').onclick = function(){
      var bt = this; bt.disabled = true;
      api('ticket_new', { title: box.querySelector('#tks').value, message: box.querySelector('#tkb').value }).then(function(r){
        if (r.ok) openTicket(r.ticket_id); else { bt.disabled = false; var e = box.querySelector('#tkm'); e.style.color = '#b91c1c'; e.textContent = r.error || DOWN; }
      });
    };
  }
  function tickets(){
    loading();
    api('tickets').then(function(r){
      var list = (r.ok && r.tickets) || [];
      box.innerHTML = '<button type="button" class="btn btn-c" id="tkn" style="margin-bottom:10px">➕ تیکت جدید</button>'
        + (list.length ? list.map(function(t){ return '<div class="pl" style="cursor:pointer" data-t="' + t.id + '"><div class="pn">' + (t.unread_member == 1 ? '🔴 ' : '') + esc(t.subject) + '</div><div class="pd">' + esc((r.statuses || {})[t.status] || '') + ' • ' + fdate(t.updated_at) + '</div></div>'; }).join('')
        : '<p style="text-align:center;color:var(--mut);padding:12px">تیکتی ندارید.</p>');
      box.querySelector('#tkn').onclick = function(){ newTicket([]); };
      Array.prototype.forEach.call(box.querySelectorAll('[data-t]'), function(el){ el.onclick = function(){ openTicket(el.getAttribute('data-t')); }; });
    });
  }
  function openTicket(id){
    loading();
    api('ticket', { ticket_id: id }).then(function(r){
      if (!r.ok) { box.innerHTML = '<p class="err" style="display:block">' + esc(r.error || DOWN) + '</p>'; return; }
      var t = r.ticket;
      box.innerHTML = '<button type="button" class="link" id="tkbk" style="font-size:12.5px;margin-bottom:8px">→ همه تیکت‌ها</button><div class="pn" style="font-weight:700;margin-bottom:8px">🎫 ' + esc(t.subject) + ' <small style="color:var(--mut);font-weight:400">(' + esc(t.status_label) + ')</small></div>'
        + r.messages.map(function(x){ return '<div class="tkm ' + (x.mine ? 'me' : 'them') + '"><small>' + esc(x.author) + ' • ' + fdate(x.at, true) + '</small>' + esc(x.body) + '</div>'; }).join('')
        + (t.status !== 'closed' ? '<textarea id="tkr" rows="3" maxlength="6000" placeholder="پاسخ شما…" class="inpt"></textarea><div style="display:flex;gap:6px;margin-top:6px"><button type="button" class="btn btn-c" id="tkrs" style="width:auto;padding:8px 16px">ارسال</button><button type="button" class="link" id="tkcl">✔ مشکل حل شد؛ بستن تیکت</button></div>' : '<p style="font-size:12.5px;color:var(--mut)">این تیکت بسته شده است.</p>');
      box.querySelector('#tkbk').onclick = tickets;
      var rs = box.querySelector('#tkrs'); if (rs) rs.onclick = function(){ var v = box.querySelector('#tkr').value.trim(); if (!v) return; rs.disabled = true; api('ticket_reply', { ticket_id: id, message: v }).then(function(x){ if (x.ok) openTicket(id); else { rs.disabled = false; alert(x.error || DOWN); } }); };
      var cl = box.querySelector('#tkcl'); if (cl) cl.onclick = function(){ api('ticket_close', { ticket_id: id }).then(function(){ openTicket(id); }); };
    });
  }
  Array.prototype.forEach.call(m.querySelectorAll('.stabs button'), function(b){ b.onclick = function(){ tab(b.getAttribute('data-t')); }; });
  if (S.ext) S.ext.unread_tickets = 0;
  ask();
}

function fdate(s, withTime){
  if (!s) return '';
  var d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return '';
  return withTime ? d.toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('fa-IR');
}

// نوار محدودیت کنار کادر تایپ: زمان دقیق آزاد شدن + دکمه ارتقا (به سبک Claude)
var limTimer = null;
function renderLimit(){
  var el = document.getElementById('limbar'), q = S.quota; if (!el) return;
  var inp = document.getElementById('inp'), snd = document.getElementById('snd'), comp = inp ? inp.closest('.comp') : null;
  el.innerHTML = ''; if (limTimer) { clearTimeout(limTimer); limTimer = null; }
  var locked = !!(q && q.allowed === false && q.reason);
  if (comp) comp.classList.toggle('locked', locked);
  if (inp) { inp.disabled = locked; inp.placeholder = locked ? 'تا آزاد شدن سقف، امکان ارسال پیام نیست' : (S.gen ? inp.placeholder : 'سوال خود را بنویسید…'); }
  if (snd) snd.disabled = locked || S.busy;
  if (!q) return;
  var up = C.canBuy && !X().imp;
  var d = document.createElement('div');
  if (locked) {
    var t = { window: 'به سقف پیام‌های این بازه رسیده‌اید', daily: 'به سقف پیام‌های امروز رسیده‌اید', weekly: 'به سقف پیام‌های این هفته رسیده‌اید', monthly: 'به سقف پیام‌های ماهانه اشتراک رسیده‌اید' }[q.reason] || 'پیام‌های شما تمام شده است';
    var sub = q.reset_text ? '📅 سهمیه بعدی شما: ' + q.reset_text + (q.can_borrow ? ' — یا همین حالا از سهمیه ' + (q.reason === 'weekly' ? 'هفته‌های' : 'روزهای') + ' بعد استفاده کنید.' : (up ? '؛ یا همین حالا پلن را ارتقا دهید.' : '.')) : (up ? 'برای ادامه، یکی از بسته‌ها را تهیه کنید.' : '');
    d.className = 'limcard';
    d.innerHTML = '<span class="li">⏳</span><div class="lt"><b>' + esc(t) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</div><div class="lbtns">'
      + (q.can_borrow && !X().imp ? '<button type="button" class="rs" data-k="borrow">♻️ ریست رایگان</button>' : '') + (up ? '<button type="button" data-k="up">⭐ ارتقای پلن</button>' : '') + '</div>';
    var rb = d.querySelector('[data-k=borrow]');
    if (rb) rb.onclick = function(){
      if (d.querySelector('.lwarn')) return;
      var w = document.createElement('div'); w.className = 'lwarn';
      w.innerHTML = '⚠️ ' + esc(q.borrow_warning || 'با ریست رایگان از سهمیه دوره بعد استفاده می‌کنید و ممکن است اشتراک شما زودتر از یک ماه به اتمام برسد.') + '<div><button type="button" data-k="yes">بله، ادامه می‌دهم</button><button type="button" class="no" data-k="no">انصراف</button></div>';
      d.appendChild(w);
      w.querySelector('[data-k=no]').onclick = function(){ w.remove(); };
      w.querySelector('[data-k=yes]').onclick = function(){
        var yb = this; yb.disabled = true; yb.textContent = '⏳ …';
        api('quota_borrow', { value: q.reason }).then(function(r){
          if (r.quota) { S.quota = r.quota; }
          if (r.ext) { S.ext = r.ext; }
          renderQuota();
          if (!r.ok) { alert(r.error || DOWN); return; }
          var inp2 = document.getElementById('inp'); if (inp2) inp2.focus();
        });
      };
    };
    var ub = d.querySelector('[data-k=up]'); if (ub) ub.onclick = function(){ showPlans(); };
    if (q.reset_at) {
      var ms0 = new Date(q.reset_at).getTime() - Date.now();
      if (ms0 > 0 && ms0 < 26 * 3600000) limTimer = setTimeout(refreshQuota, ms0 + 1500);
    }
    el.appendChild(d); return;
  } else if (q.win && q.win.left > 0 && q.win.left <= 3) {
    d.className = 'limcard warn';
    d.innerHTML = '<div class="lt">' + fa(q.win.left) + ' پیام تا ' + esc(q.win.next_text || 'پایان این بازه') + ' باقی مانده است</div>' + (up ? '<button type="button">ارتقا</button>' : '');
  } else if (q.remaining > 0 && q.remaining <= 3) {
    // هشدار نزدیک شدن به پایان پیام‌ها + لینک خرید
    d.className = 'limcard warn';
    d.innerHTML = '<div class="lt">فقط ' + fa(q.remaining) + ' پیام دیگر باقی مانده است' + (up ? '؛ برای ادامه بدون وقفه بسته تهیه کنید.' : '.') + '</div>' + (up ? '<button type="button">⭐ خرید بسته</button>' : '');
  } else return;
  var b = d.querySelector('button'); if (b) b.onclick = function(){ showPlans(); };
  el.appendChild(d);
}
/** دایره رنگی کنار نوار تایپ: نزدیک‌ترین محدودیت اشتراک (روزانه، هفتگی، ماهانه، بازه، کل پیام‌ها) */
function ringInfo(q){
  if (!q || q.mode !== 'paid') return null;
  var c = [];
  function add(name, left, limit, next){ if (limit > 0) c.push({ n: name, left: Math.max(0, left), limit: limit, r: Math.max(0, left) / limit, next: next || '' }); }
  if (q.day && !q.day.borrowed) add('امروز', q.day.left, q.day.limit, 'تمدید: ' + (q.day.next_text || 'فردا'));
  if (q.week && !q.week.borrowed) add('این هفته', q.week.left, q.week.limit, 'تمدید: ' + q.week.next_text);
  if (q.month) add('این ماه', q.month.left, q.month.limit, 'تمدید: ' + q.month.next_text);
  if (q.win) add('این بازه ' + fa(q.win.hours) + ' ساعته', q.win.left, q.win.limit, q.win.next_text ? 'آزاد شدن: ' + q.win.next_text : '');
  if (q.paid_total > 0) add('کل اشتراک', q.paid_left, q.paid_total, q.paid_until ? 'اعتبار تا ' + fdate(q.paid_until) : '');
  if (!c.length) return null;
  c.sort(function(a, b){ return a.r - b.r; });
  return { min: c[0], all: c };
}
function ringColor(r){ return r <= 0 ? '#dc2626' : (r < 0.1 ? '#ef4444' : (r < 0.25 ? '#f97316' : (r < 0.5 ? '#eab308' : '#16a34a'))); }
function renderRing(){
  var b = document.getElementById('qring'), w = document.getElementById('sendw'), qe = document.getElementById('quota'); if (!b) return;
  var q = S.quota, info = (!X().imp) ? ringInfo(q) : null;
  if (qe) { qe.classList.toggle('qcl', !!info); qe.onclick = null; }
  if (!info) { b.style.display = 'none'; if (w) w.title = ''; return; }
  var r = (q.allowed === false) ? 0 : info.min.r, col = ringColor(r), R = 25, L = 2 * Math.PI * R;
  b.style.display = '';
  var tip = info.min.n + ': ' + fa(info.min.left) + ' از ' + fa(info.min.limit) + ' پیام باقی مانده';
  b.title = tip; if (w) w.title = tip;
  b.classList.toggle('pulse', r < 0.1);
  b.innerHTML = '<svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="' + R + '" fill="none" stroke-width="3.5"/><circle class="fg" cx="28" cy="28" r="' + R + '" fill="none" stroke="' + col + '" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="' + L.toFixed(2) + '" stroke-dashoffset="' + (L * (1 - Math.min(1, r))).toFixed(2) + '"/></svg>';
  // جزئیات: با زدن روی خط وضعیت زیر نوار تایپ
  if (qe) {
    if (!qe.querySelector('.qdot')) qe.insertAdjacentHTML('afterbegin', '<span class="qdot"></span>');
    qe.querySelector('.qdot').style.background = col;
    qe.onclick = function(ev){
      if (ev.target.closest && ev.target.closest('button')) return;
      ev.stopPropagation();
      var host = w || qe, old = host.querySelector('.qpop'); if (old) { old.remove(); return; }
      var p = document.createElement('div'); p.className = 'qpop';
      p.innerHTML = '<b>📊 وضعیت اشتراک شما</b>' + info.all.map(function(x){ var cc = ringColor(x.r); return '<div class="r"><span>' + esc(x.n) + '</span><span>' + fa(x.left) + ' / ' + fa(x.limit) + '</span></div><div class="bar"><span style="width:' + Math.round(x.r * 100) + '%;background:' + cc + '"></span></div>' + (x.next ? '<div class="r"><i>' + esc(x.next) + '</i></div>' : ''); }).join('');
      host.appendChild(p);
      setTimeout(function(){ document.addEventListener('click', function h(){ p.remove(); document.removeEventListener('click', h); }); }, 0);
    };
  }
}
function renderQuota(){
  var q = S.quota, el = document.getElementById('quota'); if (!el) return;
  renderLimit(); setTimeout(renderRing, 0);
  if (!q) { el.textContent = ''; return; }
  var parts = [];
  if (q.mode === 'gift' && q.gift_left > 0) parts.push('🎁 شارژ هدیه (مدل‌های تخصصی): ' + fa(q.gift_left) + ' پیام');
  if (q.mode === 'free' && q.free_left > 0) parts.push('چت رایگان: ' + fa(q.free_left) + ' پیام');
  if (q.remaining >= 0 && q.mode !== 'free' && q.mode !== 'gift') parts.push('پیام‌های باقی‌مانده: ' + fa(q.remaining));
  if (q.credit_left > 0) parts.push('💰 شارژ: ' + fa(q.credit_left) + ' تومان');
  if (q.today_left >= 0) parts.push('امروز: ' + fa(q.today_left));
  if (q.paid_until) parts.push('اعتبار بسته تا ' + fdate(q.paid_until));
  el.textContent = parts.join(' • ');
  if (q.paid_days_left != null && q.paid_days_left <= 7 && q.paid_left > 0) {
    var w = document.createElement('div'); w.style.cssText = 'color:#b45309;font-weight:600;margin-top:3px';
    w.textContent = '⏳ اعتبار بسته شما ' + fa(Math.max(1, q.paid_days_left)) + ' روز دیگر به پایان می‌رسد. ';
    if (C.canBuy && !X().imp) { var wl = document.createElement('button'); wl.type = 'button'; wl.className = 'link'; wl.style.cssText = 'font-size:12px;font-weight:700'; wl.textContent = 'تمدید بسته'; wl.onclick = function(){ showPlans(); }; w.appendChild(wl); }
    el.appendChild(w);
  }
  if (C.canBuy && !X().imp && (q.remaining >= 0 || q.mode === 'free' || q.mode === 'credit')) { var b = document.createElement('button'); b.className = 'buyb'; b.textContent = q.mode === 'paid' ? '⭐ تمدید / ارتقا' : '⭐ ارتقای بسته'; b.onclick = function(){ showPlans(); }; el.appendChild(b); }
}

// ---------- بسته‌ها و ارتقا (صفحه کامل) ----------
function showPlans(){
  var box = openView('⭐ بسته‌ها و ارتقا');
  box.innerHTML = loadingHtml();
  api('plans').then(function(r){
    var plans = (r.ok && r.plans) || [], st = r.status;
    var h = '';
    if (st) {
      var cur = (st.current || []).map(function(c){ return '<li><b>' + esc(c.name) + '</b> — ' + (c.credit > 0 ? '💰 ' + fa(c.credit_left) + ' از ' + fa(c.credit) + ' تومان شارژ' : fa(c.left) + ' از ' + fa(c.total) + ' پیام') + (c.expires ? ' • تا ' + fdate(c.expires) : '') + '</li>'; }).join('');
      h += '<div class="card stc"><div class="pf-h" style="margin-top:0">📊 وضعیت فعلی شما</div>'
        + (cur ? '<ul class="plf">' + cur + '</ul>' : '')
        + (st.gift_left > 0 ? '<div>🎁 شارژ هدیه با مدل‌های تخصصی: <b>' + fa(st.gift_left) + '</b> پیام</div>' : '')
        + (st.free_left > 0 ? '<div>💬 چت رایگان (مدل‌های پایه): <b>' + fa(st.free_left) + '</b> پیام</div>' : (st.free_left < 0 ? '<div>💬 چت رایگان (مدل‌های پایه): نامحدود</div>' : ''))
        + (!cur && !(st.gift_left > 0) && !st.free_left ? '<div style="color:var(--mut)">در حال حاضر بسته فعالی ندارید.</div>' : '') + '</div>';
    }
    // کارت به کارت (نسخه ۵۶): وضعیت فیش‌های ارسالی
    var rcs = r.receipts || [];
    if (rcs.length) h += '<div class="card stc"><div class="pf-h" style="margin-top:0">🧾 فیش‌های واریزی شما</div>' + rcs.map(function(x){
      return '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:4px 0;border-bottom:1px dashed var(--line)"><span>' + esc(x.plan) + ' — ' + fa(x.amount) + ' تومان</span><b>' + esc(x.label) + (x.status === 'rejected' && x.reason ? ' — ' + esc(x.reason) : '') + '</b></div>';
    }).join('') + '</div>';
    if (!plans.length) { box.innerHTML = h + '<p style="color:#64748b;text-align:center;padding:14px">در حال حاضر بسته‌ای برای خرید وجود ندارد.</p>' + (C.support ? '<p class="sup" style="border:none">' + esc(C.support) + '</p>' : ''); return; }
    function priceHtml(p, fin){
      if (p.free && fin == null) return '<span style="color:#059669">رایگان</span>';
      var pr = fin != null ? fin : p.price;
      var old = (p.original && p.original > pr) ? '<s class="old">' + fa(p.original) + '</s>' : '';
      return '<span style="white-space:nowrap">' + (pr > 0 ? fa(pr) + ' تومان' : 'رایگان') + '</span>' + old;
    }
    h += '<div style="display:flex;gap:6px;margin-bottom:12px"><input id="dcode" placeholder="کد تخفیف (اختیاری)" dir="ltr" class="inpt" style="flex:1;text-transform:uppercase"><button type="button" class="btn" id="dapply" style="background:#f1f5f9;color:var(--txt);padding:8px 12px;font-size:13px">اعمال</button></div><div id="dmsg" style="font-size:12px;margin:-6px 0 10px"></div><div class="plg">'
      + plans.map(function(p){
        var info = [p.kind === 'credit' ? '💰 ' + fa(p.credit) + ' تومان شارژ' : fa(p.messages) + ' پیام'];
        if (p.days > 0) info.push('اعتبار ' + fa(p.days) + ' روز');
        if (p.daily_limit > 0) info.push('تا ' + fa(p.daily_limit) + ' پیام در روز');
        if (p.bots > 1) info.push('معتبر در ' + fa(p.bots) + ' چت‌بات');
        var off = p.percent > 0 ? '<div class="pofr">🔥 ' + fa(p.percent) + '٪ تخفیف' + (p.until ? ' تا ' + fdate(p.until + ' 12:00:00') : '') + '</div>' : '';
        var feats = (p.features || []);
        return '<div class="pl" id="pl' + p.id + '"><span class="pp" data-pp="' + p.id + '">' + priceHtml(p) + '</span><div class="pn">' + esc(p.name) + '</div>' + off
          + '<div class="pd">' + info.join(' • ') + (p.description ? '<br>' + esc(p.description) : '') + '</div>'
          + (feats.length ? '<ul class="plf">' + feats.map(function(f){ return '<li>' + esc(f) + '</li>'; }).join('') + '</ul>' : '')
          + (p.free || r.zp !== false ? '<button class="btn btn-c" data-p="' + p.id + '">' + (p.free ? '🎁 فعال‌سازی رایگان' : 'پرداخت و فعال‌سازی') + '</button>' : '')
          + (!p.free && r.c2c ? '<button class="btn" data-c2c="' + p.id + '" style="margin-top:6px;background:#fff;color:var(--c);border:1.5px solid var(--c)">💳 کارت به کارت و ارسال فیش</button>' : '') + '</div>';
      }).join('') + '</div>' + (C.support ? '<div class="sup" style="border:none;padding:8px 0">' + esc(C.support) + '</div>' : '');
    box.innerHTML = h;
    var codeEl = box.querySelector('#dcode'), dmsg = box.querySelector('#dmsg');
    if (S.focusPlan) { var fp = box.querySelector('#pl' + S.focusPlan); if (fp) { fp.style.borderColor = 'var(--c)'; fp.scrollIntoView(); } S.focusPlan = 0; }
    box.querySelector('#dapply').onclick = function(){
      var code = codeEl.value.trim(); if (!code) return;
      dmsg.style.color = 'var(--mut)'; dmsg.textContent = '…';
      var paid = plans.filter(function(p){ return !p.free; }), anyOk = false, done = 0, lastErr = '';
      if (!paid.length) { dmsg.textContent = ''; return; }
      paid.forEach(function(p){
        api('check_code', { plan_id: p.id, code: code }).then(function(x){
          var el = box.querySelector('[data-pp="' + p.id + '"]');
          if (x.ok) { anyOk = true; el.innerHTML = priceHtml({ original: p.original > p.price ? p.original : p.price }, x.price); }
          else { lastErr = x.error || DOWN; el.innerHTML = priceHtml(p); }
          if (++done === paid.length) { dmsg.style.color = anyOk ? '#059669' : '#b91c1c'; dmsg.textContent = anyOk ? '✓ کد تخفیف اعمال شد.' : lastErr; }
        });
      });
    };
    Array.prototype.forEach.call(box.querySelectorAll('[data-c2c]'), function(btn){
      btn.onclick = function(){ var pl = plans.filter(function(x){ return x.id == btn.getAttribute('data-c2c'); })[0]; if (pl) c2cForm(pl, codeEl.value.trim(), r.c2c); };
    });
    Array.prototype.forEach.call(box.querySelectorAll('[data-p]'), function(btn){
      btn.onclick = function(){
        var label = btn.textContent;
        btn.disabled = true; btn.textContent = '…';
        api('buy', { plan_id: btn.getAttribute('data-p'), code: codeEl.value.trim() }).then(function(x){
          if (x.ok && x.free) { closeView(true); if (x.quota) S.quota = x.quota; refreshMe(); addMsg('a', '✅ ' + (x.message || '')); }
          else if (x.ok && x.pay_url) {
            if (C.embed) { window.open(x.pay_url, '_blank'); btn.disabled = false; btn.textContent = label; }
            else location.href = x.pay_url;
          } else { btn.disabled = false; btn.textContent = label; alertBox(box, x.error || DOWN); box.scrollTop = 0; }
        });
      };
    });
  });
}
// ---------- کارت به کارت: نمایش شماره کارت و ارسال فیش (نسخه ۵۶) ----------
function c2cImg(file){
  return new Promise(function(res, rej){
    var fr = new FileReader();
    fr.onload = function(){
      var im = new Image();
      im.onload = function(){
        var k = Math.min(1, 1600 / Math.max(im.width, im.height)), c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(im.width * k)); c.height = Math.max(1, Math.round(im.height * k));
        var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(im, 0, 0, c.width, c.height);
        res(c.toDataURL('image/jpeg', 0.85).split(',')[1]);
      };
      im.onerror = function(){ rej(); };
      im.src = fr.result;
    };
    fr.onerror = function(){ rej(); };
    fr.readAsDataURL(file);
  });
}
function c2cForm(p, code, card){
  var box = openView('💳 کارت به کارت');
  box.innerHTML = loadingHtml();
  (code ? api('check_code', { plan_id: p.id, code: code }) : Promise.resolve({ ok: false })).then(function(x){
    var amount = x && x.ok ? x.price : p.price, useCode = x && x.ok ? code : '';
    box.innerHTML = '<p style="font-size:13px;line-height:2;color:var(--mut);margin-bottom:6px">بسته <b style="color:var(--txt)">«' + esc(p.name) + '»</b> — مبلغ را به کارت زیر واریز کنید و تصویر فیش (یا اسکرین‌شات رسید) را بفرستید. پس از بررسی و تأیید، بسته فعال می‌شود.</p>'
      + '<div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:14px;padding:14px 16px;line-height:2;margin-bottom:8px">'
      + '<div style="font-size:12.5px;opacity:.85">مبلغ قابل واریز</div><div style="font-size:20px;font-weight:800">' + fa(amount) + ' تومان</div>'
      + '<div style="font-size:12.5px;opacity:.85;margin-top:4px">شماره کارت' + (card.bank ? ' — ' + esc(card.bank) : '') + '</div>'
      + '<div dir="ltr" style="font-size:20px;font-weight:800;letter-spacing:2px;text-align:center;font-family:monospace">' + esc(card.card) + '</div>'
      + (card.name ? '<div style="font-size:13px">به نام: <b>' + esc(card.name) + '</b></div>' : '') + (card.sheba ? '<div dir="ltr" style="font-size:12px;opacity:.9">' + esc(card.sheba) + '</div>' : '')
      + (card.note ? '<div style="font-size:12.5px;opacity:.9">' + esc(card.note) + '</div>' : '') + '</div>'
      + '<div style="text-align:center;margin-bottom:10px"><button type="button" class="btn" id="c2ccp" style="width:auto;padding:5px 14px;font-size:12.5px;background:#eef2ff;color:#3730a3">📋 کپی شماره کارت</button></div>'
      + '<label style="font-weight:700;font-size:13px">🧾 تصویر فیش واریزی *</label><input type="file" id="c2cf" accept="image/*" class="inpt" style="width:100%;margin:4px 0 8px">'
      + '<input id="c2ct" class="inpt" dir="ltr" maxlength="60" placeholder="کد پیگیری / شماره مرجع (اختیاری)" style="width:100%;margin-bottom:8px">'
      + '<input id="c2cn" class="inpt" maxlength="120" placeholder="نام واریزکننده یا ۴ رقم آخر کارت (اختیاری)" style="width:100%;margin-bottom:8px">'
      + '<div class="err" id="c2ce"></div><button type="button" class="btn btn-c" id="c2cs" style="width:100%">ارسال فیش</button>';
    box.querySelector('#c2ccp').onclick = function(){ try { navigator.clipboard.writeText(String(card.card).replace(/\D/g, '')); this.textContent = '✓ کپی شد'; } catch (e) {} };
    var err = box.querySelector('#c2ce'), sb = box.querySelector('#c2cs');
    sb.onclick = function(){
      var f = box.querySelector('#c2cf').files[0];
      err.style.display = 'none';
      if (!f) { err.textContent = 'تصویر فیش را انتخاب کنید.'; err.style.display = 'block'; return; }
      sb.disabled = true; sb.textContent = '⏳ در حال ارسال…';
      c2cImg(f).then(function(b64){
        return api('c2c_submit', { plan_id: p.id, code: useCode, data: b64, mime: 'image/jpeg', value: box.querySelector('#c2ct').value.trim(), title: box.querySelector('#c2cn').value.trim() });
      }, function(){ return { ok: false, error: 'فایل انتخاب‌شده تصویر نیست.' }; }).then(function(r){
        sb.disabled = false; sb.textContent = 'ارسال فیش';
        if (r.login_required) { closeView(true); loginRequired(); return; }
        if (!r.ok) { err.textContent = r.error || DOWN; err.style.display = 'block'; return; }
        box.innerHTML = '<div style="text-align:center;padding:24px 10px;line-height:2.2"><div style="font-size:40px">✅</div><b>' + esc(r.message || 'فیش شما ثبت شد.') + '</b><br><span style="color:var(--mut);font-size:13px">وضعیت فیش را در «بسته‌ها و ارتقا» می‌بینید.</span><br><button type="button" class="btn btn-c" id="c2cb" style="width:auto;padding:8px 18px;margin-top:10px">بازگشت به گفتگو</button></div>';
        box.querySelector('#c2cb').onclick = function(){ closeAllViews(); try { history.go(-2); } catch (e) {} };
      });
    };
  });
}
function alertBox(box, msg){ var e = document.createElement('div'); e.className = 'err'; e.style.display = 'block'; e.textContent = msg; box.insertBefore(e, box.firstChild); }
/** به‌روزرسانی امکانات و سهمیه پس از خرید */
function refreshMe(){
  api('me').then(function(r){ if (!r.ok) return; S.quota = r.quota; S.ext = r.ext || S.ext; var keep = S.tid; viewChat(); if (keep) openThread(keep); });
}

// ---------- صفحه یکپارچه چت‌بات‌ها ----------
function botAvatarUrl(b){ return b.avatar ? '?a=avatar&b=' + (b.home ? 0 : b.id) + '&v=' + encodeURIComponent(b.avatar_v || '') : ''; }
function showHub(){
  var box = openView('🤖 چت‌بات‌ها');
  box.innerHTML = loadingHtml();
  api('hub', { via: 0 }).then(function(r){
    var list = (r.ok && r.bots) || [];
    function card(b){
      var av = botAvatarUrl(b);
      var cur = (S.via || C.home) === b.id || (!S.via && b.home);
      return '<button type="button" class="hubc' + (cur ? ' on' : '') + '" data-b="' + b.id + '" data-h="' + (b.home ? 1 : 0) + '"><div class="av">' + (av ? '<img src="' + esc(av) + '" alt="">' : IC.bot) + '</div><div class="hi"><b>' + esc(b.name) + '</b><small>' + esc(b.specialty || '') + '</small></div>'
        + (cur ? '<span class="tagc">در حال گفتگو</span>' : (b.paid ? '<span class="tagc ok">بسته فعال</span>' : '')) + '</button>';
    }
    var mine = list.filter(function(b){ return b.mine; }), other = list.filter(function(b){ return !b.mine; });
    box.innerHTML = (mine.length ? '<div class="pf-h">✅ چت‌بات‌های من</div><div class="hubg">' + mine.map(card).join('') + '</div>' : '')
      + (other.length ? '<div class="pf-h">✨ سایر چت‌بات‌های ما</div><div class="hubg">' + other.map(card).join('') + '</div>' : '')
      + (!list.length ? '<p style="text-align:center;color:var(--mut);padding:16px">چت‌باتی یافت نشد.</p>' : '');
    Array.prototype.forEach.call(box.querySelectorAll('[data-b]'), function(el){
      el.onclick = function(){ var id = +el.getAttribute('data-b'); closeAllViews(); switchBot(el.getAttribute('data-h') === '1' ? 0 : id); };
    });
  });
}
function switchBot(id){
  id = +id || 0;
  if (id === C0.home) id = 0;
  S.via = id; S.tid = 0; S.pf = null; S.files = []; S.threads = []; S.folder = ''; S.tq = ''; clearCtxState();
  try { if (id) sessionStorage.setItem('hdc_via', String(id)); else sessionStorage.removeItem('hdc_via'); } catch (x) {}
  if (!id) { restoreHome(); start(); return; }
  app.innerHTML = '<div class="main"><div class="off">' + loadingHtml() + '</div></div>';
  api('config').then(function(r){
    if (!r || !r.ok || !r.config) { S.via = 0; try { sessionStorage.removeItem('hdc_via'); } catch (x) {} restoreHome(); start(); return; }
    applyConfig(r.config, !!r.available);
    start();
  });
}
var C0 = JSON.parse(JSON.stringify(C));
function restoreHome(){ Object.keys(C0).forEach(function(k){ C[k] = C0[k]; }); document.title = C.name; }
function applyConfig(cfg, available){
  C.name = cfg.name || ''; C.specialty = cfg.specialty || ''; C.welcome = cfg.welcome || ''; C.disclaimer = cfg.disclaimer || '';
  C.avatar = cfg.has_avatar ? '?a=avatar&b=' + S.via + '&v=' + encodeURIComponent(cfg.avatar_v || '') : '';
  C.canBuy = !!cfg.can_buy; C.support = cfg.support || ''; C.verify = !!cfg.verify_mobile; C.sms = !!cfg.sms; C.google = false;
  C.available = available !== false; C.hub = true;
  document.title = C.name;
}

// ---------- امکانات تکمیلی: فایل، صدا، مدل، ساخت تصویر ----------
function X(){ return S.ext || { models: [], locked_models: [], model: 0, voice: false, files: false, max_files: 10, consent: false, consent_asked: true, imp: false }; }
function renderAtts(){
  var el = document.getElementById('atts'); if (!el) return;
  el.style.display = S.files.length ? 'flex' : 'none';
  el.innerHTML = S.files.map(function(f, i){
    return '<span class="chip' + (f.err ? ' err' : '') + '">' + (f.kind === 'image' ? '🖼' : '📄') + ' <span>' + esc(f.err || f.name) + '</span>' + (f.id || f.err ? '' : ' <span class="typing"><span></span><span></span><span></span></span>') + '<button type="button" data-i="' + i + '" title="حذف">×</button></span>';
  }).join('');
  Array.prototype.forEach.call(el.querySelectorAll('button[data-i]'), function(b){ b.onclick = function(){ S.files.splice(+b.getAttribute('data-i'), 1); renderAtts(); }; });
}
function readAsDataURL(file){ return new Promise(function(res, rej){ var r = new FileReader(); r.onload = function(){ res(r.result); }; r.onerror = rej; r.readAsDataURL(file); }); }
function shrinkImage(file){
  // کوچک‌سازی تصویر پیش از ارسال (حداکثر ۱۶۰۰ پیکسل، JPEG)
  return readAsDataURL(file).then(function(url){
    return new Promise(function(res){
      var img = new Image();
      img.onload = function(){
        var max = 1600, w = img.width, h = img.height;
        if (w <= max && h <= max && file.size < 1500000) return res({ data: url, name: file.name || 'image.png' });
        var k = Math.min(1, max / Math.max(w, h)), c = document.createElement('canvas');
        c.width = Math.round(w * k); c.height = Math.round(h * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        res({ data: c.toDataURL('image/jpeg', 0.85), name: (file.name || 'image').replace(/\.[^.]+$/, '') + '.jpg' });
      };
      img.onerror = function(){ res({ data: url, name: file.name || 'image.png' }); };
      img.src = url;
    });
  });
}
function addFiles(list){
  if (!X().files) { if (X().files_lock) showPromo('files'); return; }
  list.forEach(function(file){
    if (S.files.length >= X().max_files) { alert('حداکثر ' + fa(X().max_files) + ' فایل در هر پیام.'); return; }
    var isImg = /^image\//.test(file.type), ext = ((file.name || '').split('.').pop() || '').toLowerCase();
    var f = { name: file.name || (isImg ? 'image.png' : 'file'), kind: isImg ? 'image' : 'doc', id: 0, err: '' };
    if (!isImg && ['pdf', 'docx', 'txt'].indexOf(ext) < 0) { f.err = 'نوع فایل مجاز نیست: ' + f.name; S.files.push(f); renderAtts(); return; }
    if (!isImg && file.size > 5 * 1024 * 1024) { f.err = 'حجم بیش از ۵ مگابایت: ' + f.name; S.files.push(f); renderAtts(); return; }
    S.files.push(f); renderAtts();
    (isImg ? shrinkImage(file) : readAsDataURL(file).then(function(d){ return { data: d, name: f.name }; })).then(function(x){
      var nm = x.name; if (isImg && !/\.(jpe?g|png|webp|gif)$/i.test(nm)) nm = nm.replace(/\.[^.]*$/, '') + '.png';
      return api('upload', { name: nm, data: x.data });
    }).then(function(r){
      if (r.ok && r.file) { f.id = r.file.id; f.name = r.file.name; } else { f.err = (r.error || DOWN); if (r.upgrade) showPromo('files'); }
      renderAtts();
    }).catch(function(){ f.err = DOWN; renderAtts(); });
  });
}
function setupAttach(){
  var b = document.getElementById('att'), fin = document.getElementById('fin'); if (!b) return;
  b.onclick = function(){ if (!X().files) { showPromo('files'); return; } fin.click(); };
  fin.onchange = function(){ var list = Array.prototype.slice.call(fin.files || []); fin.value = ''; addFiles(list); };
}
// کشیدن و رها کردن تصویر/فایل روی صفحه گفتگو
function setupDrop(){
  var zone = document.getElementById('mainp'), dz = document.getElementById('dropz'); if (!zone || !dz) return;
  var depth = 0;
  function has(ev){ var t = ev.dataTransfer && ev.dataTransfer.types; return t && Array.prototype.indexOf.call(t, 'Files') >= 0; }
  zone.addEventListener('dragenter', function(ev){ if (!has(ev)) return; ev.preventDefault(); depth++; dz.classList.add('on'); });
  zone.addEventListener('dragover', function(ev){ if (!has(ev)) return; ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy'; });
  zone.addEventListener('dragleave', function(ev){ if (!has(ev)) return; depth = Math.max(0, depth - 1); if (!depth) dz.classList.remove('on'); });
  zone.addEventListener('drop', function(ev){ if (!has(ev)) return; ev.preventDefault(); depth = 0; dz.classList.remove('on'); addFiles(Array.prototype.slice.call(ev.dataTransfer.files || [])); });
}
var autoSendT = null;
function cancelAutoSend(){ if (autoSendT) { clearInterval(autoSendT); autoSendT = null; var bar = document.getElementById('recbar'); if (bar) { bar.style.display = 'none'; bar.innerHTML = ''; } } }
function setupMic(){
  var b = document.getElementById('mic'); if (!b) return;
  if (!X().voice) { b.onclick = function(){ showPromo('voice'); }; return; }
  var bar = document.getElementById('recbar'), timer = null, cancelled = false;
  function stopUI(){ b.classList.remove('rec'); bar.style.display = 'none'; bar.innerHTML = ''; clearInterval(timer); }
  b.onclick = function(){
    if (S.rec) { S.rec.stop(); return; }
    if (S.busy) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) { alert('مرورگر شما ضبط صدا را پشتیبانی نمی‌کند (یا صفحه با https باز نشده است). لطفاً پیام را تایپ کنید.'); return; }
    unlockAudio();
    cancelAutoSend();
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function(stream){
      var mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].filter(function(m){ return MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0] || '';
      var rec, chunks = [], t0 = Date.now();
      try { rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined); } catch (x) { rec = new MediaRecorder(stream); }
      cancelled = false; S.rec = rec;
      rec.ondataavailable = function(e){ if (e.data && e.data.size) chunks.push(e.data); };
      rec.onstop = function(){
        stream.getTracks().forEach(function(t){ t.stop(); }); S.rec = null; stopUI();
        var secs = (Date.now() - t0) / 1000;
        if (cancelled || !chunks.length) return;
        if (secs < 1) { alert('پیام صوتی خیلی کوتاه بود؛ روی میکروفون بزنید، صحبت کنید و دوباره بزنید.'); return; }
        var blob = new Blob(chunks, { type: rec.mimeType || mime || 'audio/webm' });
        var inp = document.getElementById('inp'); inp.placeholder = '⏳ در حال تبدیل صدا به متن…'; inp.disabled = true;
        readAsDataURL(blob).then(function(d){ return api('voice', { data: d, mime: blob.type, dur: Math.round(secs * 10) / 10 }); }).then(function(r){
          inp.placeholder = 'سوال خود را بنویسید…'; inp.disabled = false;
          if (r.ok && r.text) {
            inp.value = (inp.value ? inp.value + ' ' : '') + r.text; inp.oninput && inp.dispatchEvent(new Event('input'));
            // مهلت ۴ ثانیه برای بازبینی و ویرایش؛ سپس ارسال خودکار
            var left = 4; bar.style.display = 'flex';
            bar.innerHTML = '✍️ <span>متن پیام صوتی آماده است؛ ارسال خودکار تا <b id="asl">' + fa(left) + '</b> ثانیه دیگر (برای ویرایش روی متن بزنید)</span> <button class="link" id="asn">ارسال الآن</button> <button class="link" id="asc">ویرایش</button>';
            autoSendT = setInterval(function(){ left--; var e2 = document.getElementById('asl'); if (e2) e2.textContent = fa(left); if (left <= 0) { cancelAutoSend(); send(); } }, 1000);
            document.getElementById('asn').onclick = function(){ cancelAutoSend(); send(); };
            document.getElementById('asc').onclick = function(){ cancelAutoSend(); inp.focus(); };
          } else { if (r.upgrade) showPromo('voice'); else alert(r.error || DOWN); }
        });
      };
      rec.start(250); b.classList.add('rec'); bar.style.display = 'flex';
      bar.innerHTML = '🔴 <span id="rectime">0:00</span> در حال ضبط… برای پایان دوباره روی میکروفون بزنید. <button class="link" id="reccancel">لغو</button>';
      document.getElementById('reccancel').onclick = function(){ cancelled = true; if (S.rec) S.rec.stop(); };
      var tm = document.getElementById('rectime');
      timer = setInterval(function(){ var s2 = Math.floor((Date.now() - t0) / 1000); tm.textContent = Math.floor(s2 / 60) + ':' + ('0' + s2 % 60).slice(-2); if (s2 >= 180) rec.stop(); }, 500);
    }).catch(function(err){
      var n = err && err.name;
      alert(n === 'NotAllowedError' || n === 'SecurityError' ? 'دسترسی به میکروفون داده نشد. از تنظیمات مرورگر (آیکون قفل کنار آدرس سایت) اجازه میکروفون را فعال کنید.' : (n === 'NotFoundError' ? 'میکروفونی پیدا نشد.' : 'ضبط صدا ممکن نشد. لطفاً پیام را تایپ کنید.'));
    });
  };
}
// ساخت تصویر / ویدیو
function setupGen(){
  var b = document.getElementById('genb'), l = document.getElementById('genl'); if (!b) return;
  var e = X();
  var opts = [];
  if (e.gen_image) opts.push(['image', '🖼 ساخت تصویر', e.gen_image === true, e.gen_cost ? e.gen_cost.image : 0]);
  if (e.gen_video) opts.push(['video', '🎬 ساخت ویدیو', e.gen_video === true, e.gen_cost ? e.gen_cost.video : 0]);
  // گالری طرح‌های آماده (نسخه ۵۱): مرور برای همه؛ ساخت برای بسته‌های دارای امکان
  (e.gallery || []).forEach(function(k){ opts.push(['gal:' + k, k === 'video' ? '🗂 ویدیو از روی نمونه‌ها' : '🗂 طرح از روی نمونه‌های آماده', e['gen_' + k] === true, -1]); });
  l.innerHTML = '<div style="font-size:11px;color:var(--mut);padding:4px 10px 6px">ساخت با هوش مصنوعی</div>' + opts.map(function(o){
    var sm = o[3] < 0 ? 'گالری با دسته‌بندی و جستجو' + (o[2] ? '' : ' — ساخت با ارتقای بسته') : (o[2] ? 'هر ' + (o[0] === 'video' ? 'ویدیو' : 'تصویر') + ' = ' + fa(o[3]) + ' پیام از سهمیه' : 'با ارتقای بسته فعال می‌شود');
    return '<button type="button" class="mdo' + (o[2] ? '' : ' lk') + '" data-g="' + o[0] + '">' + (o[2] ? '' : '<b>🔒</b>') + o[1] + '<small>' + sm + '</small></button>';
  }).join('');
  b.onclick = function(ev){ ev.stopPropagation(); l.classList.toggle('open'); };
  Array.prototype.forEach.call(l.querySelectorAll('[data-g]'), function(o){
    o.onclick = function(){
      l.classList.remove('open');
      var k = o.getAttribute('data-g');
      if (k.indexOf('gal:') === 0) { showGallery(k.slice(4)); return; }
      if (o.classList.contains('lk')) { showPromo(k); return; }
      setCtx('gen', k);
      document.getElementById('inp').focus();
    };
  });
}
function renderModel(){
  var b = document.getElementById('mdb'), l = document.getElementById('mdl'); if (!b) return;
  var ms = X().models || [], lk = X().locked_models || [], cur = ms.filter(function(m){ return m.id == X().model; })[0] || ms[0];
  if (!cur && !lk.length) { b.parentNode.style.display = 'none'; return; }
  b.parentNode.style.display = '';
  b.innerHTML = cur ? '<span>' + esc(cur.title) + '</span> <b>×' + fa(cur.ratio) + '</b> ▾' : '<span>مدل</span> ▾';
  l.innerHTML = '<div style="font-size:11px;color:var(--mut);padding:4px 10px 6px">انتخاب مدل پاسخ‌گو (عدد = نسبت مصرف پیام)</div>' + ms.map(function(m){
    return '<button type="button" class="mdo' + (cur && m.id == cur.id ? ' on' : '') + '" data-m="' + m.id + '"><b>×' + fa(m.ratio) + '</b>' + esc(m.title)
      + '<small>' + (m.desc ? esc(m.desc) + ' — ' : '') + (m.cost > 1 ? 'هر پیام = ' + fa(m.cost) + ' پیام از سهمیه' : 'هر پیام = ۱ پیام') + '</small></button>';
  }).join('') + (lk.length ? '<div style="font-size:11px;color:var(--mut);padding:8px 10px 4px;border-top:1px dashed var(--line);margin-top:4px">⭐ مدل‌های تخصصی (با ارتقای بسته)</div>' + lk.map(function(m){
    return '<button type="button" class="mdo lk" data-lk="' + m.id + '"><b>🔒</b>' + esc(m.title) + '<small>' + (m.desc ? esc(m.desc) : 'پاسخ‌های دقیق‌تر و تخصصی‌تر') + '</small></button>';
  }).join('') : '');
  b.onclick = function(ev){ ev.stopPropagation(); l.classList.toggle('open'); };
  if (!S._mdl) { S._mdl = 1; document.addEventListener('click', function(){ ['mdl', 'genl'].forEach(function(k){ var x = document.getElementById(k); if (x) x.classList.remove('open'); }); }); }
  Array.prototype.forEach.call(l.querySelectorAll('[data-m]'), function(o){
    o.onclick = function(){ S.ext.model = +o.getAttribute('data-m'); l.classList.remove('open'); renderModel(); api('model', { model_id: S.ext.model }); };
  });
  Array.prototype.forEach.call(l.querySelectorAll('[data-lk]'), function(o){ o.onclick = function(){ l.classList.remove('open'); showPromo('model'); }; });
}
// پخش صوتی: یک عنصر صوتی که با اولین لمس کاربر فعال می‌شود (برای iPhone و مرورگرهایی که پخش خودکار را مسدود می‌کنند)
var SILENT = 'data:audio/mp3;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQxAADB8AhSmxhIIEVCSiJrDCQBTcu3UrAIwUdkRgQbFAZC1CQEwTJ9mjRvBA4UOLD8nKVOWfh+UlK3z/177OXrfOdKl7pyn3Xf//WreyTRUoAWgBgkOAGbZHBgG1OF6zM82DWbZaUmMBptgQhGjsyYqc9ae9XFz280948NMBWInljyzsNRFLPWdnZGWrddDsjK1unuSrVN9jJsK8KuQtQCtMBjCEtImISdNKJOopIpBFpNSMbIHCSRpRR5iakjTiyzLhchUUBwCgyKiweBv/7UsQbg8isVNoMPMjAAAA0gAAABEVFGmgqK////9bP/6XCykxBTUUzLjEwMKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';
function unlockAudio(){
  if (S.player) return S.player;
  var a = new Audio(); a.preload = 'auto'; S.player = a;
  try { a.src = SILENT; var p = a.play(); if (p && p.catch) p.catch(function(){}); } catch (x) {}
  return a;
}
function b64Blob(b64, mime){ var bin = atob(b64), n = bin.length, u = new Uint8Array(n); for (var i = 0; i < n; i++) u[i] = bin.charCodeAt(i); return new Blob([u], { type: mime }); }
function playTTS(btn, id){
  var a = unlockAudio();
  if (S.audioBtn) { a.pause(); var was = S.audioBtn; S.audioBtn = null; was.textContent = '🔊 پخش'; if (was === btn) return; }
  btn.textContent = '⏳';
  api('tts', { message_id: id }).then(function(r){
    if (!r.ok || !r.data) { btn.textContent = '🔊 پخش'; if (r.upgrade) showPromo('voice'); else alert(r.error || DOWN); return; }
    try { if (S.audioUrl) URL.revokeObjectURL(S.audioUrl); } catch (x) {}
    S.audioUrl = URL.createObjectURL(b64Blob(r.data, r.mime || 'audio/mpeg'));
    a.src = S.audioUrl; S.audioBtn = btn;
    btn.textContent = '⏹ توقف';
    a.onended = function(){ btn.textContent = '🔊 پخش'; S.audioBtn = null; };
    var p = a.play(); if (p && p.catch) p.catch(function(){ btn.textContent = '▶️ پخش دوباره'; S.audioBtn = null; });
  });
}
// ---------- اجازه دسترسی همکاران ----------
function setConsent(v, cb){
  api('consent', { value: v ? '1' : '0' }).then(function(x){
    if (x.ok) { if (S.ext) { S.ext.consent = !!x.consent; S.ext.consent_asked = true; } if (S.pf) { S.pf.consent = !!x.consent; S.pf.consent_asked = true; } renderConsentBar(); }
    else if (x.need_plan && C.canBuy) { if (confirm((x.error || 'نظارت مشاور با تهیه اشتراک فعال می‌شود.') + '\nمشاهده بسته‌ها؟')) showPlans(); }
    else alert(x.error || DOWN);
    if (cb) cb(x);
  });
}
function renderConsentBar(){
  var el = document.getElementById('cbar'); if (!el) return;
  var e = X();
  var hint = (S.secureHint && !e.imp) ? '<div class="cbar imp"><div class="cb-in"><div class="cb-t">🔐 برای محافظت از پرونده و گفتگوهایتان یک رمز عبور بگذارید تا فقط خودتان بتوانید وارد حساب شوید.</div><button type="button" class="cb-yes" id="cbpw">گذاشتن رمز</button></div></div>' : '';
  if (hint) setTimeout(function(){ var b = document.getElementById('cbpw'); if (b) b.onclick = function(){ showProfile(true); }; }, 0);
  if (e.imp) {
    el.innerHTML = '<div class="cbar imp"><div class="cb-in"><div class="cb-t">👁 شما با اجازه مراجع، پنل او را مشاهده می‌کنید. پیام‌هایی که بفرستید از سهمیه او کم می‌شود. روی هر پیام «💬 کامنت» را بزنید تا نظرتان ثبت شود.</div></div></div>';
    return;
  }
  if (!S.ext || e.consent_asked || e.sup_lock) { el.innerHTML = hint; return; }   // بدون اشتراک: پرسش اجازه نمایش داده نمی‌شود
  el.innerHTML = hint + '<div class="cbar"><div class="cb-in"><div class="cb-t">🤝 آیا اجازه می‌دهید همکاران این مجموعه پرونده و گفتگوهای شما را ببینند و برایتان نظر، کامنت و نتیجه جلسه ثبت کنند؟ (هر زمان از «پرونده من» قابل تغییر است)</div>'
    + '<button type="button" class="cb-yes" id="cby">✅ تأیید</button><button type="button" class="cb-no" id="cbn">عدم تأیید</button></div></div>';
  document.getElementById('cby').onclick = function(){ this.disabled = true; setConsent(true); };
  document.getElementById('cbn').onclick = function(){ this.disabled = true; setConsent(false); };
}
function consentHtml(r){
  // مخاطب بدون اشتراک: بخش نمایش داده می‌شود اما غیرفعال است
  if (r.sup_lock && !r.imp) {
    return '<div class="cs lock" id="pfcs"><b>🤝 دسترسی مشاور و همکاران به پرونده</b><br>'
      + (r.consent_hold ? 'قبلاً اجازه داده بودید؛ با تهیه یا تمدید اشتراک، دسترسی مشاور خودکار دوباره فعال می‌شود.' : 'با این امکان، مشاور و همکاران این مجموعه گفتگوهای شما را بررسی می‌کنند و برایتان نظر، کامنت و نتیجه جلسه ثبت می‌کنند.')
      + '<div class="cs-b"><button type="button" class="btn cb-yes" disabled>✅ تأیید دسترسی همکاران</button></div>'
      + '<div class="cs-lk">🔒 ' + esc(r.sup_text || 'نظارت مشاور با تهیه اشتراک فعال می‌شود.') + '</div>'
      + (C.canBuy ? '<button type="button" class="btn btn-c cs-buy" data-buy="1">⭐ تهیه اشتراک</button>' : '') + '</div>';
  }
  var st = r.imp ? 'none' : (r.consent ? 'on' : (r.consent_asked ? 'off' : 'none'));
  var h = '<div class="cs ' + st + '" id="pfcs"><b>🤝 دسترسی همکاران به پرونده</b><br>';
  if (r.consent) h += '✅ <b>تأیید کرده‌اید:</b> همکاران این مجموعه پرونده و گفتگوهای شما را می‌بینند و برایتان نظر، کامنت و نتیجه جلسه ثبت می‌کنند.';
  else if (r.consent_asked) h += '⛔ <b>تأیید نکرده‌اید:</b> هیچ‌کس (حتی مدیر مجموعه) پرونده و گفتگوهای شما را نمی‌بیند.';
  else h += 'هنوز تصمیم نگرفته‌اید. تا وقتی تأیید نکنید، هیچ‌کس پرونده و گفتگوهای شما را نمی‌بیند.';
  if (r.imp) h += '<br><span style="font-size:12px;color:var(--mut)">(این اجازه را فقط خود مراجع می‌تواند تغییر دهد.)</span>';
  else h += '<div class="cs-b">' + (r.consent ? '' : '<button type="button" class="btn cb-yes" data-c="1">✅ تأیید دسترسی همکاران</button>')
          + ((r.consent || !r.consent_asked) ? '<button type="button" class="btn cb-no" data-c="0">' + (r.consent ? '⛔ لغو دسترسی همکاران' : 'عدم تأیید') + '</button>' : '') + '</div>';
  return h + '</div>';
}
function securityHtml(a){
  var h = '<div class="sec" id="pfsec"><b>🔐 امنیت حساب</b><div style="font-size:12px;color:var(--mut);margin:4px 0 10px;line-height:1.9">ورود به حساب شما (شماره ' + esc(a.mobile) + ')' + (a.sms ? ' با کد پیامکی،' : '') + ' با رمز عبور' + (a.google_ready ? ' یا حساب گوگل' : '') + ' ممکن است.</div>';
  if (a.imp) return h + '<div style="font-size:12px;color:var(--mut)">(تنظیمات امنیتی را فقط خود مراجع می‌تواند تغییر دهد.)</div></div>';
  h += '<div class="pf-h" style="margin-top:0">' + (a.has_password ? 'تغییر رمز عبور' : 'گذاشتن رمز عبور') + '</div>'
    + (a.has_password ? '<input id="spc" type="password" placeholder="رمز فعلی" autocomplete="current-password" dir="ltr">' : '')
    + '<input id="sp1" type="password" placeholder="رمز جدید (حداقل ۶ کاراکتر)" autocomplete="new-password" dir="ltr"><input id="sp2" type="password" placeholder="تکرار رمز جدید" autocomplete="new-password" dir="ltr">'
    + '<button type="button" class="btn btn-c" id="spgo">💾 ذخیره رمز</button><div id="spmsg" style="font-size:12px;margin-top:6px;text-align:center"></div>';
  if (a.google_ready && !S.via) {
    h += '<div class="pf-h">حساب گوگل</div>' + (a.google
      ? '<div style="font-size:12.5px">✅ متصل به <span dir="ltr">' + esc(a.email) + '</span> <button type="button" class="link" id="gun">قطع اتصال</button></div>'
      : '<button type="button" class="btn btn-o" id="glk">' + IC.g + 'اتصال حساب گوگل</button>');
  }
  return h + '</div>';
}
function bindSecurity(box, a){
  var go = box.querySelector('#spgo');
  if (go) go.onclick = function(){
    var m = box.querySelector('#spmsg'), p1 = box.querySelector('#sp1').value, p2 = box.querySelector('#sp2').value;
    if (p1 !== p2) { m.style.color = '#b91c1c'; m.textContent = 'تکرار رمز با رمز جدید یکسان نیست.'; return; }
    go.disabled = true; m.style.color = ''; m.textContent = '…';
    api('set_password', { current: box.querySelector('#spc') ? box.querySelector('#spc').value : '', password: p1 }).then(function(x){
      go.disabled = false; m.style.color = x.ok ? '#059669' : '#b91c1c'; m.textContent = x.ok ? '✓ ' + (x.msg || 'ذخیره شد.') : (x.error || DOWN);
      if (x.ok) { S.secureHint = false; S.pf = null; renderConsentBar(); box.querySelector('#sp1').value = ''; box.querySelector('#sp2').value = ''; if (!a.has_password) { a.has_password = true; var o = box.querySelector('#pfsec'); o.outerHTML = securityHtml(a); bindSecurity(box, a); var mm = box.querySelector('#spmsg'); mm.style.color = '#059669'; mm.textContent = '✓ ' + (x.msg || 'ذخیره شد.'); } }
    });
  };
  var gl = box.querySelector('#glk'); if (gl) gl.onclick = function(){ googleGo('g_link', function(er){ alert(er); }); };
  var gu = box.querySelector('#gun'); if (gu) gu.onclick = function(){
    if (!confirm('اتصال حساب گوگل قطع شود؟')) return;
    api('g_unlink').then(function(x){ if (!x.ok) { alert(x.error || DOWN); return; } a.google = false; a.email = ''; S.pf = null; var o = box.querySelector('#pfsec'); o.outerHTML = securityHtml(a); bindSecurity(box, a); });
  };
}
function showProfile(focusPw){
  var m = document.createElement('div'); m.className = 'mdl';
  m.innerHTML = '<div class="mbox"><h3>📋 پرونده من <button type="button" title="بستن">×</button></h3><div id="pfb">' + loadingHtml() + '</div></div>';
  document.body.appendChild(m);
  function close(){ m.remove(); }
  m.onclick = function(ev){ if (ev.target === m) close(); };
  m.querySelector('h3 button').onclick = close;
  function render(r){
    var acc = r.account;
    var box = m.querySelector('#pfb');
    if (!r.ok) { box.innerHTML = '<p class="err" style="display:block">' + esc(r.error || DOWN) + '</p>'; return; }
    var prof = r.profile || {}, intake = r.intake || [];
    function rowHtml(k, v, fixed){ return '<div class="pf-row"><input class="k" value="' + esc(k) + '" placeholder="عنوان (مثلاً: شغل)" maxlength="60"' + (fixed ? ' readonly' : '') + '><input class="v" value="' + esc(v) + '" placeholder="' + (fixed ? 'پاسخ خود را بنویسید' : 'مقدار') + '" maxlength="300"><button type="button" title="حذف">×</button></div>'; }
    var rowsH = '';
    intake.forEach(function(k){ rowsH += rowHtml(k, prof[k] || '', true); });
    Object.keys(prof).forEach(function(k){ if (intake.indexOf(k) < 0) rowsH += rowHtml(k, prof[k], false); });
    box.innerHTML = consentHtml(r)
      + (r.supervisions ? '<div class="note" style="background:#f0fdf4;border-color:#bbf7d0">👁 همکار شما تاکنون <b>' + fa(r.supervisions) + '</b> بار گفتگوهایتان را برای راهنمایی بهتر بررسی کرده است' + (r.supervision_last ? ' (آخرین بار: ' + fdate(r.supervision_last, true) + ')' : '') + '.</div>' : '')
      + '<div class="pf-h">اطلاعات من</div>'
      + '<p style="font-size:12.5px;color:var(--mut);margin-bottom:10px;line-height:1.9">این اطلاعات برای مشاوره دقیق‌تر استفاده می‌شود و از گفتگوهای شما هم خودکار تکمیل می‌شود. می‌توانید موارد را اضافه، ویرایش یا حذف کنید.</p>'
      + '<div id="pfrows">' + rowsH + '</div>'
      + '<button type="button" class="link" id="pfadd">+ افزودن مورد جدید</button>'
      + '<div style="margin-top:12px"><button type="button" class="btn btn-c" id="pfsave">💾 ذخیره پرونده</button><div id="pfmsg" style="font-size:12px;margin-top:8px;text-align:center"></div></div>'
      + (acc && acc.ok ? securityHtml(acc) : '')
      + (r.notes && r.notes.length ? '<div class="pf-h" style="margin-top:16px">یادداشت‌های همکاران برای شما</div>' + r.notes.map(function(n){ return '<div class="note"><b>' + esc(n.kind) + (n.title ? ' — ' + esc(n.title) : '') + ' • ' + esc(n.author || '') + ' • ' + fdate(n.date + ' 12:00:00') + '</b>' + esc(n.content) + '</div>'; }).join('') : '');
    var rowsEl = box.querySelector('#pfrows');
    function bindDel(){ Array.prototype.forEach.call(rowsEl.querySelectorAll('.pf-row button'), function(b){ b.onclick = function(){ var rw = b.parentNode; if (rw.querySelector('.k').readOnly) rw.querySelector('.v').value = ''; else rw.remove(); }; }); }
    bindDel();
    box.querySelector('#pfadd').onclick = function(){ rowsEl.insertAdjacentHTML('beforeend', rowHtml('', '', false)); bindDel(); var ks = rowsEl.querySelectorAll('.k'); ks[ks.length - 1].focus(); };
    function bindConsent(){
      var bb = box.querySelector('#pfcs [data-buy]'); if (bb) bb.onclick = function(){ showPlans(); };
      Array.prototype.forEach.call(box.querySelectorAll('#pfcs [data-c]'), function(b){
        b.onclick = function(){
          var v = b.getAttribute('data-c') === '1';
          if (!v && r.consent && !confirm('دسترسی همکاران به پرونده و گفتگوهای شما لغو شود؟')) return;
          b.disabled = true;
          setConsent(v, function(x){ if (x.ok) { r.consent = !!x.consent; r.consent_asked = true; var o = box.querySelector('#pfcs'); o.outerHTML = consentHtml(r); bindConsent(); } else b.disabled = false; });
        };
      });
    }
    bindConsent();
    if (acc && acc.ok) { bindSecurity(box, acc); if (focusPw === true) { var sec = box.querySelector('#pfsec'); if (sec) { sec.scrollIntoView(); var f = box.querySelector('#sp1'); if (f) f.focus(); } } }
    box.querySelector('#pfsave').onclick = function(){
      var pr = {};
      Array.prototype.forEach.call(rowsEl.querySelectorAll('.pf-row'), function(rw){ var k = rw.querySelector('.k').value.trim(), v = rw.querySelector('.v').value.trim(); if (k && v) pr[k] = v; });
      var msg = box.querySelector('#pfmsg'); msg.style.color = ''; msg.textContent = '…';
      api('profile_save', { profile: pr }).then(function(x){ msg.style.color = x.ok ? '#059669' : '#b91c1c'; msg.textContent = x.ok ? '✓ پرونده ذخیره شد.' : (x.error || DOWN); if (x.ok && S.pf) S.pf.profile = x.profile; });
    };
  }
  // نمایش فوری از حافظه (پیش‌بارگذاری‌شده) و به‌روزرسانی در پس‌زمینه
  var shown = '';
  if (S.pf) { render(S.pf); shown = JSON.stringify(S.pf); }
  api('profile').then(function(r){
    if (r.ok) S.pf = r;
    if (!document.body.contains(m)) return;
    var js = JSON.stringify(r);
    if (js === shown) return;
    if (shown && m.querySelector('#pfb input:focus')) return;   // در حال ویرایش: تغییر نده
    render(r);
  });
}

// ---------- آرشیو گفتگوها: پوشه، تاریخ، جستجو، تغییر نام، انتقال و حذف ----------
function loadThreads(){
  api('threads').then(function(r){ if (r.login_required) return loginRequired(); if (r.ok) { S.threads = r.threads || []; S.folders = r.folders || []; renderFolders(); renderThreads(); } });
}
function renderFolders(){
  var el = document.getElementById('fchips'); if (!el) return;
  var f = S.folders || [];
  if (S.folder && S.folder !== '-' && f.indexOf(S.folder) < 0) S.folder = '';
  var chips = [['', 'همه']].concat(f.map(function(x){ return [x, '📁 ' + x]; }));
  if (S.threads.some(function(t){ return !t.folder; }) && f.length) chips.push(['-', 'بدون پوشه']);
  el.innerHTML = chips.map(function(c){ return '<button type="button" class="fchip' + ((S.folder || '') === c[0] ? ' on' : '') + '" data-f="' + esc(c[0]) + '">' + esc(c[1]) + '</button>'; }).join('')
    + '<button type="button" class="fchip add" id="fadd" title="پوشه جدید">+ پوشه</button>'
    + (S.folder && S.folder !== '-' ? '<button type="button" class="fchip add" id="fedit" title="تغییر نام یا حذف پوشه">⚙</button>' : '');
  Array.prototype.forEach.call(el.querySelectorAll('[data-f]'), function(b){ b.onclick = function(){ S.folder = b.getAttribute('data-f'); renderFolders(); renderThreads(); }; });
  document.getElementById('fadd').onclick = function(){
    var n = prompt('نام پوشه جدید (مثلاً: کار، سلامت، خانواده):'); if (!n || !n.trim()) return;
    api('folder_add', { title: n.trim() }).then(function(x){ if (!x.ok) { alert(x.error || DOWN); return; } S.folders = x.folders; S.folder = n.trim().slice(0, 40); renderFolders(); renderThreads(); });
  };
  var fe = document.getElementById('fedit'); if (fe) fe.onclick = function(){
    menuAt(fe, [['✏️ تغییر نام پوشه', function(){ var n = prompt('نام جدید پوشه:', S.folder); if (!n || !n.trim() || n.trim() === S.folder) return; api('folder_rename', { value: S.folder, title: n.trim() }).then(function(x){ if (!x.ok) { alert(x.error || DOWN); return; } S.folder = n.trim().slice(0, 40); loadThreads(); }); }],
                ['🗑 حذف پوشه (گفتگوها حذف نمی‌شوند)', function(){ if (!confirm('پوشه «' + S.folder + '» حذف شود؟ گفتگوهای داخل آن به «بدون پوشه» منتقل می‌شوند.')) return; api('folder_delete', { value: S.folder }).then(function(){ S.folder = ''; loadThreads(); }); }]]);
  };
}
function menuAt(anchor, items){
  var old = document.getElementById('ctxm'); if (old) old.remove();
  var m = document.createElement('div'); m.id = 'ctxm'; m.className = 'ctxm';
  m.innerHTML = items.map(function(it, i){ return '<button type="button" data-i="' + i + '">' + esc(it[0]) + '</button>'; }).join('');
  document.body.appendChild(m);
  var r = anchor.getBoundingClientRect();
  m.style.top = Math.min(window.innerHeight - m.offsetHeight - 8, r.bottom + 4) + 'px';
  m.style.left = Math.max(8, Math.min(window.innerWidth - m.offsetWidth - 8, r.left)) + 'px';
  Array.prototype.forEach.call(m.querySelectorAll('button'), function(b){ b.onclick = function(ev){ ev.stopPropagation(); m.remove(); items[+b.getAttribute('data-i')][1](); }; });
  setTimeout(function(){ document.addEventListener('click', function h(){ m.remove(); document.removeEventListener('click', h); }); }, 0);
}
function renderThreads(){
  var el = document.getElementById('threads'); if (!el) return;
  var q = S.tq || '', f = S.folder || '';
  var list = S.threads.filter(function(t){
    if (f === '-' && t.folder) return false;
    if (f && f !== '-' && t.folder !== f) return false;
    if (q && String(t.title || '').toLowerCase().indexOf(q) < 0) return false;
    return true;
  });
  if (!list.length) { el.innerHTML = '<div style="color:#94a3b8;font-size:12px;text-align:center;padding:20px">' + (S.threads.length ? 'گفتگویی در این بخش نیست' : 'هنوز گفتگویی ندارید') + '</div>'; return; }
  el.innerHTML = list.map(function(t){
    return '<div class="th' + (t.id == S.tid ? ' on' : '') + '" data-id="' + t.id + '"><div class="tt2"><span>' + esc(t.title || 'گفتگو') + '</span><small>' + fdate(t.updated_at || t.created_at) + (t.folder && !f ? ' • 📁 ' + esc(t.folder) : '') + '</small></div><button class="del" data-menu="' + t.id + '" title="گزینه‌ها">⋮</button></div>';
  }).join('');
  Array.prototype.forEach.call(el.querySelectorAll('.th'), function(d){
    d.onclick = function(ev){
      var mid = ev.target.getAttribute('data-menu');
      if (mid) { ev.stopPropagation(); threadMenu(ev.target, +mid); return; }
      openThread(d.getAttribute('data-id'));
    };
  });
}
function threadMenu(anchor, id){
  var t = S.threads.filter(function(x){ return x.id == id; })[0]; if (!t) return;
  var items = [['✏️ تغییر عنوان', function(){ var n = prompt('عنوان جدید گفتگو:', t.title || ''); if (!n || !n.trim()) return; api('thread_rename', { thread_id: id, title: n.trim() }).then(function(x){ if (!x.ok) alert(x.error || DOWN); loadThreads(); }); }],
               ['📁 انتقال به پوشه…', function(){ moveMenu(anchor, t); }]];
  if (t.folder) items.push(['↩ خارج کردن از پوشه', function(){ api('thread_move', { thread_id: id, value: '' }).then(loadThreads); }]);
  items.push(['🗑 حذف گفتگو', function(){ if (confirm('این گفتگو حذف شود؟')) api('thread_delete', { thread_id: id }).then(function(){ if (S.tid == id) { S.tid = 0; showWelcome(); } loadThreads(); }); }]);
  menuAt(anchor, items);
}
function moveMenu(anchor, t){
  var items = (S.folders || []).filter(function(f){ return f !== t.folder; }).map(function(f){ return ['📁 ' + f, function(){ api('thread_move', { thread_id: t.id, value: f }).then(loadThreads); }]; });
  items.push(['+ پوشه جدید…', function(){ var n = prompt('نام پوشه جدید:'); if (!n || !n.trim()) return; api('thread_move', { thread_id: t.id, value: n.trim() }).then(loadThreads); }]);
  setTimeout(function(){ menuAt(anchor, items); }, 0);
}
function openThread(id){
  S.tid = +id; renderThreads(); clearCtx();
  if (S.toggleSide) S.toggleSide(false);
  var w = document.getElementById('wrap'); w.innerHTML = '<div class="m a">' + avatar() + '<div class="b"><span class="typing"><span></span><span></span><span></span></span></div></div>';
  api('thread', { thread_id: id }).then(function(r){
    if (S.tid != id) return;
    w.innerHTML = '';
    if (!r.ok) { addMsg('a', r.error || DOWN); return; }
    (r.messages || []).forEach(function(m){ addMsg(m.role === 'user' ? 'u' : 'a', m.content, m.sources, m.id, m.rating, m.files, m); });
    scrollDown();
  });
}
function showWelcome(){
  var w = document.getElementById('wrap'); if (!w) return;
  w.innerHTML = '<div class="hello">' + avatar() + '<h2>' + esc(C.name) + '</h2><div>' + esc(C.welcome || 'سوال خود را بپرسید.') + '</div></div>';
}

// ---------- پیام‌ها: کپی، پاسخ (ریپلای)، ویرایش، کامنت همکار، رسانه ----------
function copyText(btn, text){
  var done = function(){ var o = btn.textContent; btn.textContent = '✓ کپی شد'; setTimeout(function(){ btn.textContent = o; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done).catch(fallback); else fallback();
  function fallback(){ try { var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done(); } catch (x) {} }
}
function plainOf(text){ return String(text || '').replace(/\*\*/g, '').replace(/\[([0-9۰-۹]{1,2})\]/g, '').replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g, '$1').trim(); }
function clearCtxState(){ S.reply = null; S.edit = null; S.gen = ''; S.gsize = 'auto'; S.remMode = false; S.act = null; S.actRef = 0; }
// اقدام‌های آماده ساخت تصویر/ویدیو (نسخه ۴۲): یک کلیک = یک کار مشخص
function gaList(kind, needRef){ return (X().gen_actions || []).filter(function(a){ return a.kind === kind && (!needRef || a.ref !== 'none'); }); }
function gaFind(id){ var l = X().gen_actions || []; for (var i = 0; i < l.length; i++) if (l[i].id == id) return l[i]; return null; }
function gaPick(a, refMid){
  if (X()['gen_' + a.kind] !== true) { showPromo(a.kind); return; }
  var hasFile = S.files.some(function(f){ return f.id && f.kind === 'image'; });
  clearCtxState(); S.gen = a.kind; S.act = a; S.actRef = refMid || 0;
  // اگر نه پرسشی دارد و نه تصویر کم است، همان لحظه اجرا شود
  if (!a.ask && (a.ref !== 'required' || refMid || hasFile)) { renderCtx(); genRun(a.kind, '', 'auto'); return; }
  renderCtx(); var inp = document.getElementById('inp'); if (inp) inp.focus();
}
function clearCtx(){ clearCtxState(); renderCtx(); }
function setCtx(kind, v){
  clearCtxState();
  if (kind === 'reply') S.reply = v; else if (kind === 'edit') S.edit = v; else if (kind === 'gen') S.gen = v; else if (kind === 'rem') S.remMode = true;
  renderCtx();
}
function renderCtx(){
  var el = document.getElementById('ctxbar'), inp = document.getElementById('inp'); if (!el) return;
  var h = '';
  var szs = '<select id="gsz" title="ابعاد"><option value="auto">📐 خودکار</option><option value="1024x1024">مربع</option><option value="1536x1024">افقی</option><option value="1024x1536">عمودی</option></select>';
  if (S.reply && S.reply.img) h = '✏️ <span><b>ویرایش تصویر:</b> بگویید چه تغییری بدهم (مثلاً «پس‌زمینه را آبی کن»، «نوشته را بزرگ‌تر کن»)</span>' + szs;
  else if (S.reply) h = '↩ <span><b>در پاسخ به:</b> ' + esc(S.reply.text.slice(0, 120)) + '</span>';
  else if (S.remMode) h = '⏰ <span><b>ثبت یادآور:</b> بنویسید چه چیزی و کی؛ مثلاً «فردا ساعت ۱۰ جلسه با آقای احمدی، یک ساعت قبل خبرم کن»</span>';
  else if (S.edit) h = '✏️ <span><b>ویرایش پیام</b> — با ارسال، این پیام و پاسخ‌های بعد از آن با پاسخ تازه جایگزین می‌شود.</span>';
  else if (S.act) h = esc(S.act.icon || '⚡') + ' <span><b>' + esc(S.act.title) + ':</b> '
      + (S.act.ask ? esc(S.act.ask) + ' را بنویسید و ارسال را بزنید' : (S.act.ref === 'required' && !S.actRef ? 'تصویر را با 📎 بفرستید و ارسال را بزنید (بدون تصویر، روی آخرین تصویر همین گفتگو انجام می‌شود)' : 'ارسال را بزنید'))
      + (S.actRef ? ' — روی تصویر انتخاب‌شده' : '') + '</span>';
  else if (S.gen) h = (S.gen === 'video' ? '🎬' : '🖼') + ' <span><b>' + (S.gen === 'video' ? 'ساخت ویدیو' : 'ساخت تصویر') + ':</b> توضیح دهید چه ' + (S.gen === 'video' ? 'ویدیویی' : 'تصویری') + ' می‌خواهید؛ با 📎 می‌توانید تصویر مرجع هم بفرستید (هر ' + (S.gen === 'video' ? 'ویدیو' : 'تصویر') + ' = ' + fa((X().gen_cost || {})[S.gen] || 1) + ' پیام)</span>'
      + (S.gen === 'video' ? '<select id="gsz" title="ابعاد"><option value="auto">📐 خودکار</option><option value="1280x720">افقی</option><option value="720x1280">عمودی</option></select>' : szs) + gmSel(S.gen)
      + (gaList(S.gen).length ? '<div class="gacts">' + gaList(S.gen).map(function(a){ return '<button type="button" data-ga="' + a.id + '" title="' + esc(a.desc || '') + '">' + esc((a.icon || '⚡') + ' ' + a.title) + '</button>'; }).join('') + '</div>' : '');
  el.style.display = h ? 'flex' : 'none';
  el.innerHTML = h ? h + '<button type="button" title="لغو">×</button>' : '';
  if (h) el.querySelector(':scope > button:last-child').onclick = function(){ var wasEdit = !!S.edit; clearCtx(); if (wasEdit && inp) inp.value = ''; };
  Array.prototype.forEach.call(el.querySelectorAll('[data-ga]'), function(b){ b.onclick = function(){ var a = gaFind(b.getAttribute('data-ga')); if (a) gaPick(a, 0); }; });
  var gs = document.getElementById('gsz'); if (gs) { gs.value = S.gsize || 'auto'; gs.onchange = function(){ S.gsize = gs.value; }; }
  var gm = document.getElementById('gmd'); if (gm) gm.onchange = function(){ gmSet(gm.getAttribute('data-k'), +gm.value); };
  if (inp) inp.placeholder = S.act ? (S.act.ask ? (S.act.hint || S.act.ask) : 'برای اجرا «ارسال» را بزنید') : S.remMode ? 'مثلاً: پس‌فردا عصر ۵ تماس با خانم رضایی' : (S.reply && S.reply.img) ? 'چه تغییری بدهم؟ مثلاً: رنگ پس‌زمینه را سرمه‌ای کن و نوشته را بزرگ‌تر' : (S.gen ? (S.gen === 'video' ? 'مثلاً: موج‌های دریا هنگام غروب، حرکت آرام دوربین' : 'مثلاً: لوگوی یک کافه با فنجان قهوه، سبک مینیمال') : 'سوال خود را بنویسید…');
}
var mc = 0;
function addMsg(role, text, sources, id, rating, files, meta){
  var w = document.getElementById('wrap'); var hello = w.querySelector('.hello'); if (hello) hello.remove();
  meta = meta || {};
  var mid = id || ('n' + (++mc));
  var d = document.createElement('div'); d.className = 'm ' + role; if (id) d.setAttribute('data-mid', id);
  var rq = meta.reply ? '<div class="rq"><b>' + (meta.reply.role === 'user' ? 'پیام شما' : 'پاسخ') + ':</b> ' + esc(meta.reply.text) + '</div>' : '';
  var cmt = (X().imp && id) ? '<button type="button" class="cmb" title="ثبت کامنت همکار روی این پیام">💬 کامنت</button>' : '';
  if (role === 'u') {
    d.innerHTML = '<div class="bw"><div class="b">' + rq + '<div class="bt"></div></div><div class="tools ut">'
      + '<button type="button" class="cp" title="کپی">📋 کپی</button>'
      + (id && !X().imp ? '<button type="button" class="ed" title="ویرایش و ارسال دوباره">✏️ ویرایش</button><button type="button" class="rp" title="پاسخ به این پیام">↩ پاسخ</button>' : '')
      + cmt + '</div><div class="cmts"></div></div>';
    d.querySelector('.bt').textContent = text;
    var ub = d.querySelector('.b');
    if (files && files.length) { var fw = document.createElement('div'); fw.className = 'ufiles'; fw.innerHTML = files.map(function(f){ return '<span class="chip">' + (f.kind === 'image' ? '🖼' : '📄') + ' <span>' + esc(f.name) + '</span></span>'; }).join(''); ub.appendChild(fw); }
    var ed = d.querySelector('.ed'); if (ed) ed.onclick = function(){
      if (S.busy) return;
      var inp = document.getElementById('inp'); inp.value = text; inp.dispatchEvent(new Event('input'));
      setCtx('edit', { id: id }); inp.focus();
    };
  } else {
    var rt = id ? '<button type="button" class="rt' + (rating == 1 ? ' on' : '') + '" data-v="1" title="پاسخ مفید بود">👍</button><button type="button" class="rt' + (rating == -1 ? ' on' : '') + '" data-v="-1" title="پاسخ مفید نبود">👎</button>' : '';
    var tt = (id && (X().voice || X().voice_lock)) ? '<button type="button" class="tt">🔊 پخش</button>' : '';
    d.innerHTML = avatar() + '<div class="bw"><div class="b">' + md(text, mid) + '<div class="media"></div>' + sourcesHtml(sources, mid) + '<div class="tools"><button type="button" class="cp">📋 کپی</button>' + tt + (id && !X().imp ? '<button type="button" class="rp">↩ پاسخ</button>' : '') + rt + cmt + '</div></div><div class="cmts"></div></div>';
    var ttb = d.querySelector('.tt'); if (ttb) ttb.onclick = function(){ if (!X().voice) { showPromo('voice'); return; } playTTS(ttb, id); };
    Array.prototype.forEach.call(d.querySelectorAll('.rt'), function(b){
      b.onclick = function(){
        var on = b.classList.contains('on'), v = on ? 0 : +b.getAttribute('data-v');
        Array.prototype.forEach.call(d.querySelectorAll('.rt'), function(x){ x.classList.remove('on'); });
        if (!on) b.classList.add('on');
        api('rate', { message_id: id, value: v });
      };
    });
    if (meta.media && meta.media.length) renderMedia(d.querySelector('.media'), meta.media, id);
  }
  d.querySelector('.cp').onclick = function(){ copyText(this, role === 'u' ? text : plainOf(text)); };
  var rp = d.querySelector('.rp'); if (rp) rp.onclick = function(){
    var img = role !== 'u' && (meta.media || []).some(function(x){ return x.kind === 'image' && x.status === 'done'; });
    setCtx('reply', { id: id, text: img ? '🖼 تصویر ساخته‌شده' : plainOf(text), img: img }); document.getElementById('inp').focus();
  };
  var cb = d.querySelector('.cmb'); if (cb) cb.onclick = function(){ commentBox(d, id); };
  if (meta.comments && meta.comments.length) renderComments(d, meta.comments);
  w.appendChild(d); scrollDown(); return d;
}
function renderComments(d, list){
  var el = d.querySelector('.cmts'); if (!el) return;
  el.innerHTML = list.map(function(c){
    return '<div class="cmt' + (c.private ? ' pv' : '') + '"><small>💬 ' + esc(c.author || 'همکار') + (c.private ? ' • 🔒 محرمانه (فقط همکاران و هوش مصنوعی)' : '') + ' • ' + fdate(c.at, true) + (X().imp ? ' <button type="button" class="link" data-cd="' + c.id + '">حذف</button>' : '') + '</small>' + esc(c.body) + '</div>';
  }).join('');
  Array.prototype.forEach.call(el.querySelectorAll('[data-cd]'), function(b){ b.onclick = function(){ if (!confirm('این کامنت حذف شود؟')) return; api('comment_del', { value: b.getAttribute('data-cd') }).then(function(){ b.closest('.cmt').remove(); }); }; });
}
function commentBox(d, id){
  var el = d.querySelector('.cmts'); if (!el || el.querySelector('.cmf')) return;
  var f = document.createElement('div'); f.className = 'cmf';
  f.innerHTML = '<textarea rows="3" maxlength="3000" placeholder="نظر یا اصلاح شما درباره این پیام… (هوش مصنوعی در پاسخ‌های بعدی آن را اعمال می‌کند)"></textarea>'
    + '<label><input type="checkbox" checked> نمایش به مراجع</label><div><button type="button" class="btn btn-c">ثبت کامنت</button> <button type="button" class="link">انصراف</button></div>';
  el.appendChild(f);
  var ta = f.querySelector('textarea'); ta.focus();
  f.querySelector('.link').onclick = function(){ f.remove(); };
  f.querySelector('.btn-c').onclick = function(){
    var bt = this; if (!ta.value.trim()) return; bt.disabled = true;
    api('comment_add', { message_id: id, message: ta.value, value: f.querySelector('input').checked ? '1' : '0' }).then(function(x){
      if (!x.ok) { bt.disabled = false; alert(x.error || DOWN); return; }
      f.remove();
      var c = document.createElement('div'); c.className = 'cmt' + (f.querySelector('input').checked ? '' : ' pv');
      c.innerHTML = '<small>💬 شما • همین حالا</small>'; c.appendChild(document.createTextNode(ta.value)); el.appendChild(c);
    });
  };
}
function mediaUrl(m, dl){ return '?a=media&id=' + m.id + (S.via ? '&b=' + S.via : '') + (dl ? '&dl=1' : ''); }
function fsz(m){ return m.size || ''; }
function renderMedia(el, list, mid){
  el.innerHTML = '';
  list.forEach(function(m){
    if (mid && !m.mid) m.mid = mid;
    var box = document.createElement('div'); box.className = 'gm';
    if (m.status === 'done') {
      box.innerHTML = m.kind === 'video'
        ? '<video controls playsinline preload="metadata" src="' + mediaUrl(m) + '"></video>'
        : '<a href="' + mediaUrl(m) + '" target="_blank" rel="noopener"><img src="' + mediaUrl(m) + '" alt="تصویر ساخته‌شده" loading="lazy"></a>';
      box.innerHTML += '<a class="gdl" href="' + mediaUrl(m, 1) + '">⬇️ دانلود</a>';
    } else if (m.status === 'failed') {
      box.innerHTML = '<div class="gph err2">⚠️ ' + esc(m.error || 'ساخت ناموفق بود.') + '</div>';
    } else {
      box.innerHTML = '<div class="gph"><span class="typing"><span></span><span></span><span></span></span><div>در حال ساخت ' + (m.kind === 'video' ? 'ویدیو' : 'تصویر') + '… <span class="gp"></span></div><div class="gbar"><i></i></div><div class="gst">در صف ساخت • <span class="gt">۰ ثانیه</span></div></div>';
      m._t0 = m._t0 || Date.now();
      (function(bx, mm){ var tm = setInterval(function(){ if (!document.body.contains(bx)) return clearInterval(tm); var e = bx.querySelector('.gt'); if (e) e.textContent = prgTime(Date.now() - mm._t0); }, 1000); })(box, m);
      pollMedia(box, m, 0);
    }
    el.appendChild(box);
    if (m.status === 'done') {
      var info = document.createElement('div'); info.className = 'gmi';
      var h = '';
      if (m.size) h += '<span>💾 ' + esc(m.size) + '</span>';
      if (m.tokens != null && m.tokens > 0) h += '<span class="tk">🧮 ' + fa(m.tokens) + ' توکن</span>';
      if (m.kind === 'image' && m.mid && !X().imp && X().gen_image) h += '<button type="button" class="ged">✏️ ویرایش این تصویر</button>';
      if (m.kind === 'image' && m.mid && !X().imp && X().gen_image && gaList('image', true).length) h += '<button type="button" class="gqa">⚡ کارهای آماده</button>';
      info.innerHTML = h;
      if (h) {
        el.appendChild(info);
        var gb = info.querySelector('.ged'); if (gb) gb.onclick = function(){
          if (X().gen_image !== true) { showPromo('image'); return; }
          setCtx('reply', { id: m.mid, text: '🖼 تصویر ساخته‌شده', img: true }); document.getElementById('inp').focus();
        };
        var qa = info.querySelector('.gqa'); if (qa) qa.onclick = function(ev){
          ev.stopPropagation();
          var items = gaList('image', true).map(function(a){ return [(a.icon || '⚡') + ' ' + a.title, function(){ gaPick(a, m.mid); }]; });
          gaList('video', true).forEach(function(a){ items.push([(a.icon || '🎬') + ' ' + a.title, function(){ gaPick(a, m.mid); }]); });
          menuAt(qa, items);
        };
      }
    }
  });
}
function pollMedia(box, m, n){
  if (n > 120 || !document.body.contains(box)) return;
  setTimeout(function(){
    if (!document.body.contains(box)) return;
    api('media_status', { value: m.id }).then(function(r){
      if (r.ok && (r.status === 'done' || r.status === 'failed')) {
        m.status = r.status; m.error = r.error || '';
        var par = box.parentNode;
        if (par && r.item) { for (var k in r.item) m[k] = r.item[k]; }
        if (par) { var holder = document.createElement('div'); renderMedia(holder, [m], m.mid); par.insertBefore(holder, box); box.remove(); while (holder.firstChild) par.insertBefore(holder.firstChild, holder); holder.remove(); }
        if (r.status === 'done') refreshQuota();
        return;
      }
      var g = box.querySelector('.gp'); if (g && r.progress) g.textContent = fa(r.progress) + '٪';
      // پیشرفت واقعی از سرویس ساخت
      var gb = box.querySelector('.gbar i'); if (gb) gb.style.width = Math.max(3, Math.min(100, +r.progress || 0)) + '%';
      var gs = box.querySelector('.gst'); if (gs && r.ok) { var tx = r.status === 'queued' ? 'در صف ساخت' : (+r.progress >= 99 ? 'در حال دریافت و ذخیره فایل' : 'در حال ساخت'); gs.firstChild.nodeValue = tx + ' • '; }
      pollMedia(box, m, n + 1);
    });
  }, n ? 8000 : 4000);
}
function refreshQuota(){ api('me').then(function(r){ if (r.ok) { S.quota = r.quota; renderQuota(); } }); }
function scrollDown(){ var m = document.getElementById('msgs'); if (m) m.scrollTop = m.scrollHeight; }

// ---------- روند تولید پاسخ، تصویر و ویدیو (مرحله‌ای + تایپ تدریجی) ----------
function prgTime(ms){ var t = Math.max(0, Math.round(ms / 1000)); return t < 60 ? fa(t) + ' ثانیه' : fa(Math.floor(t / 60)) + ' دقیقه' + (t % 60 ? ' و ' + fa(t % 60) + ' ثانیه' : ''); }
var PRG_STEPS = {
  text:  { title: 'در حال فکر کردن…', exp: 9,  steps: [['📖', 'خواندن پیام شما', 0], ['🧠', 'بررسی منابع دانش و سوابق گفتگو', 1.2], ['✍️', 'نوشتن پاسخ', 3.2]] },
  files: { title: 'در حال فکر کردن…', exp: 14, steps: [['📖', 'خواندن پیام شما', 0], ['📎', 'بررسی فایل‌های پیوست', 1], ['🧠', 'بررسی منابع دانش و سوابق گفتگو', 3.5], ['✍️', 'نوشتن پاسخ', 6]] },
  rem:   { title: 'در حال ثبت یادآور…', exp: 5, steps: [['📖', 'خواندن پیام شما', 0], ['🗓', 'تشخیص موضوع، تاریخ و ساعت', 0.8], ['⏰', 'ثبت یادآور و تنظیم اعلان', 2.5]] },
  image: { title: 'در حال ساخت تصویر…', exp: 40, steps: [['📖', 'خواندن درخواست', 0], ['🌐', 'ترجمه و آماده‌سازی دستور ساخت', 1.2], ['🎨', 'ساخت تصویر', 4], ['💾', 'آماده‌سازی و ذخیره تصویر', 35]] },
  edit:  { title: 'در حال ویرایش تصویر…', exp: 45, steps: [['📖', 'خواندن تغییرات درخواستی', 0], ['🌐', 'ترجمه و آماده‌سازی دستور ویرایش', 1.2], ['🎨', 'اعمال تغییرات روی تصویر', 4], ['💾', 'آماده‌سازی و ذخیره تصویر', 38]] },
  video: { title: 'در حال آماده‌سازی ویدیو…', exp: 12, steps: [['📖', 'خواندن درخواست', 0], ['🌐', 'ترجمه و آماده‌سازی دستور ساخت', 1.2], ['🎬', 'ارسال به سرویس ساخت ویدیو', 4]] }
};
/** نمایش روند در حباب پاسخ؛ خروجی: { finish(ok) → عنصر خلاصه برای بالای پاسخ } */
function prgStart(bubble, kind){
  var cfg = PRG_STEPS[kind] || PRG_STEPS.text, t0 = Date.now(), cur = -1, stopped = false;
  bubble.innerHTML = '<div class="prg" role="status" aria-live="polite"><div class="ph"><span class="sp"></span><b>' + esc(cfg.title) + '</b><span class="pe">' + prgTime(0) + '</span></div><ol>'
    + cfg.steps.map(function(st){ return '<li><span class="i">' + st[0] + '</span><span>' + esc(st[1]) + '</span></li>'; }).join('') + '</ol><div class="pbar"><i></i></div></div>';
  var lis = bubble.querySelectorAll('.prg li'), pe = bubble.querySelector('.pe'), pb = bubble.querySelector('.pbar i');
  function tick(){
    if (stopped) return;
    var sec = (Date.now() - t0) / 1000, k = 0;
    cfg.steps.forEach(function(st, i){ if (sec >= st[2]) k = i; });
    if (k !== cur) { cur = k; Array.prototype.forEach.call(lis, function(li, i){ li.className = i < k ? 'ok' : (i === k ? 'on' : ''); if (i < k) li.querySelector('.i').textContent = '✓'; }); }
    pe.textContent = prgTime(Date.now() - t0);
    pb.style.width = Math.min(94, 94 * (1 - Math.exp(-sec / cfg.exp))).toFixed(1) + '%';
  }
  tick(); var iv = setInterval(tick, 500);
  return {
    kind: kind,
    finish: function(ok, real){
      stopped = true; clearInterval(iv);
      if (!ok) return null;
      // نوع واقعی پاسخ (از سرور) بر حدس اولیه مقدم است
      if (real && real !== kind && !(real === 'image' && kind === 'edit') && !(real === 'text' && kind === 'files')) { kind = real; cfg = PRG_STEPS[real] || cfg; }
      var d = document.createElement('details'); d.className = 'prgd';
      var lbl = { rem: 'یادآور ثبت شد', image: 'تصویر آماده شد', edit: 'ویرایش تصویر انجام شد', video: 'ساخت ویدیو شروع شد' }[kind] || 'پاسخ آماده شد';
      d.innerHTML = '<summary>✓ ' + esc(lbl) + ' در ' + (Date.now() - t0 < 1000 ? 'کمتر از یک ثانیه' : prgTime(Date.now() - t0)) + '</summary><ol>'
        + cfg.steps.map(function(st){ return '<li><span class="i">✓</span><span>' + esc(st[1]) + '</span></li>'; }).join('') + '</ol>';
      return d;
    }
  };
}
/** نوع روند بر اساس درخواست (تشخیص نهایی با سرور است) */
function prgKind(text, hasFiles, reply){
  if (S.remMode) return 'rem';
  if (reply && reply.img) return 'edit';
  var t = text || '';
  if (/(ویدیو|ویدئو|فیلم کوتاه|کلیپ|انیمیشن)/.test(t) && /(بساز|درست کن|تولید کن|طراحی کن|بسازی)/.test(t)) return 'video';
  if (/(تصویر|عکس|لوگو|پوستر|بنر|طرح|نقاشی|کاور|استیکر)/.test(t) && /(بساز|درست کن|تولید کن|طراحی کن|بکش|بسازی)/.test(t)) return 'image';
  if (/(یادم بنداز|یادآوری کن|یادآور بذار|یادآور بگذار)/.test(t)) return 'rem';
  return hasFiles ? 'files' : 'text';
}
/** تایپ تدریجی پاسخ (کلیک روی پاسخ = نمایش کامل) */
function typeOut(msgEl){
  var b = msgEl && msgEl.querySelector('.b'); if (!b) return;
  try { if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return; } catch (x) {}
  if (document.hidden) return;
  var blocks = [], after = [];
  Array.prototype.forEach.call(b.children, function(c){
    if (c.classList.contains('prgd')) return;
    if (c.tagName === 'BUTTON' || c.classList.contains('media') || c.classList.contains('tools') || c.classList.contains('src') || c.classList.contains('srcs') || c.classList.contains('rmx')) after.push(c); else blocks.push(c);
  });
  var items = [], total = 0;
  blocks.forEach(function(bl){
    var w = document.createTreeWalker(bl, NodeFilter.SHOW_TEXT, null, false), n, list = [];
    while ((n = w.nextNode())) { if (n.nodeValue.length) { list.push({ n: n, t: n.nodeValue }); total += n.nodeValue.length; } }
    items.push({ el: bl, nodes: list });
  });
  if (total < 25 || total > 12000) return;
  items.forEach(function(it){ it.el.classList.add('tw-hide'); it.nodes.forEach(function(x){ x.n.nodeValue = ''; }); });
  after.forEach(function(c){ c.classList.add('tw-wait'); });
  // سرعت بر اساس زمان (نه تعداد فریم) تا در همه دستگاه‌ها یکسان باشد
  var dur = Math.max(600, Math.min(3500, total * 6)), t0 = Date.now(), shown = 0, bi = 0, ni = 0, ci = 0, done = false;
  var msgs = document.getElementById('msgs');
  function nearBottom(){ return !msgs || msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 140; }
  function finish(){
    if (done) return; done = true;
    items.forEach(function(it){ it.el.classList.remove('tw-hide'); it.nodes.forEach(function(x){ x.n.nodeValue = x.t; }); });
    after.forEach(function(c){ c.classList.remove('tw-wait'); });
    b.removeEventListener('click', finish);
  }
  b.addEventListener('click', finish);
  function step(){
    if (done) return;
    if (document.hidden) return finish();
    var stick = nearBottom(), want = Math.min(total, Math.ceil(total * (Date.now() - t0) / dur)), budget = Math.max(1, want - shown);
    shown += budget;
    while (budget > 0 && bi < items.length) {
      var it = items[bi]; it.el.classList.remove('tw-hide');
      if (ni >= it.nodes.length) { bi++; ni = 0; ci = 0; continue; }
      var x = it.nodes[ni], take = Math.min(budget, x.t.length - ci);
      ci += take; budget -= take; x.n.nodeValue = x.t.slice(0, ci);
      if (ci >= x.t.length) { ni++; ci = 0; }
    }
    if (stick) scrollDown();
    if (bi >= items.length) { finish(); if (stick) scrollDown(); return; }
    requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}
function prgAttach(msgEl, sum){ if (!sum || !msgEl) return; var b = msgEl.querySelector('.b'); if (b) b.insertBefore(sum, b.firstChild); }

// انتخاب مدل ساخت تصویر/ویدیو (نسخه ۵۹)
function gmList(k){ var g = X().gen_models || {}; return g[k] || []; }
function gmGet(k){
  var l = gmList(k); if (!l.length) return 0;
  var v = (S.gmod || {})[k];
  if (!v) { try { v = +JSON.parse(localStorage.getItem('hdc_gm') || '{}')[k] || 0; } catch (x) { v = 0; } }
  return l.some(function(m){ return m.id == v; }) ? v : ((X().gen_model || {})[k] || l[0].id);
}
function gmSet(k, v){ S.gmod = S.gmod || {}; S.gmod[k] = v; try { var o = JSON.parse(localStorage.getItem('hdc_gm') || '{}'); o[k] = v; localStorage.setItem('hdc_gm', JSON.stringify(o)); } catch (x) {} }
function gmSel(k){
  var l = gmList(k); if (l.length < 2) return '';
  var cur = gmGet(k);
  return '<select id="gmd" data-k="' + k + '" title="مدل">' + l.map(function(m){ return '<option value="' + m.id + '"' + (m.id == cur ? ' selected' : '') + '>🧠 ' + esc(m.title) + (m.left > 0 ? ' (' + fa(m.left) + ' مانده)' : '') + '</option>'; }).join('') + '</select>';
}
function genRun(kind, text, gsz){
  var inp = document.getElementById('inp'), snd = document.getElementById('snd');
  if (S.busy) return;
  if (S.files.some(function(f){ return !f.id && !f.err; })) { alert('صبر کنید تا ارسال فایل‌ها تمام شود.'); return; }
  var gready = S.files.filter(function(f){ return f.id; }), gfids = gready.map(function(f){ return f.id; }), gshow = gready.map(function(f){ return { name: f.name, kind: f.kind }; });
  var act = S.act, actRef = S.actRef || 0;
  S.busy = true; snd.disabled = true; inp.value = ''; inp.style.height = 'auto'; S.files = []; renderAtts(); clearCtx();
  var gum = addMsg('u', act ? (act.icon || '⚡') + ' ' + act.title + (text ? ': ' + text : '') : (kind === 'video' ? '🎬 ساخت ویدیو: ' : '🖼 ساخت تصویر: ') + text, null, null, null, gshow);
  var tg = addMsg('a', ''), gprg = prgStart(tg.querySelector('.b'), kind === 'video' ? 'video' : 'image');
  api(kind === 'video' ? 'gen_video' : 'gen_image', { thread_id: S.tid || 0, message: text, file_ids: gfids, size: gsz, value: act ? act.id : 0, reply_to: actRef, model_id: gmGet(kind) }).then(function(r){
    var gsum = gprg.finish(r.ok); tg.remove(); S.busy = false; snd.disabled = false;
    if (r.login_required) return loginRequired();
    if (!r.ok) { var em = addMsg('a', r.error || DOWN); if ((r.upgrade || r.can_buy) && C.canBuy && !X().imp) upBtn(em); if (r.quota) { S.quota = r.quota; renderQuota(); } return; }
    if (r.user_message_id) { gum.remove(); addMsg('u', r.user_text || text, null, r.user_message_id, null, gshow); }
    var gam = addMsg('a', r.reply, null, r.message_id, 0, null, { media: r.media });
    prgAttach(gam, gsum); typeOut(gam);
    if (r.quota) { S.quota = r.quota; renderQuota(); }
    if (r.ext) { S.ext = r.ext; }
    if (!S.tid) S.tid = r.thread_id;
    loadThreads();
  });
}
// ---------- گالری طرح‌های آماده (نسخه ۵۱) ----------
function galImg(id, full){ return '?a=gal&id=' + id + (full ? '&f=1' : ''); }
function showGallery(kind){
  var ks = X().gallery || []; if (ks.indexOf(kind) < 0) kind = ks[0]; if (!kind) return;
  var box = openView('🗂 گالری طرح‌های آماده');
  box.innerHTML = loadingHtml();
  var fresh = S.galData && Date.now() - (S.galAt || 0) < 600000;
  (fresh ? Promise.resolve({ ok: true, kinds: S.galData }) : api('gallery')).then(function(r){
    if (r.login_required) { closeView(true); loginRequired(); return; }
    if (!r.ok || !r.kinds) { box.innerHTML = '<p class="gl-none">' + esc(r.error || DOWN) + '</p>'; return; }
    S.galData = r.kinds; if (!fresh) S.galAt = Date.now();
    render(S.galData[kind] ? kind : Object.keys(S.galData)[0]);
  });
  function render(k){
    var D = S.galData[k], kinds = Object.keys(S.galData), byId = {}, ui = null;
    D.items.forEach(function(it){ byId[it.id] = it; });
    box.innerHTML = '<div class="gl-list">' + (kinds.length > 1 ? '<div class="gl-tabs">' + kinds.map(function(x){ return '<button type="button" data-k="' + x + '" class="' + (x === k ? 'on' : '') + '">' + (x === 'video' ? '🎬 ویدیو' : '🖼 طرح و تصویر') + '</button>'; }).join('') + '</div>' : '')
      + '<div class="gl-wrap"><div class="gl-side"></div><div><div class="gl-grid"></div><p class="gl-none" hidden>طرحی پیدا نشد؛ عبارت دیگری بنویسید یا «🤖 هوشمند» را بزنید.</p></div></div></div><div class="gl-det" hidden></div>';
    Array.prototype.forEach.call(box.querySelectorAll('.gl-tabs button'), function(b){ b.onclick = function(){ render(b.getAttribute('data-k')); }; });
    var grid = box.querySelector('.gl-grid'), none = box.querySelector('.gl-none');
    function draw(ids){
      var list = ids ? ids.map(function(id){ return byId[id]; }).filter(Boolean) : D.items, max = 240;
      grid.innerHTML = list.slice(0, max).map(function(it){
        return '<button type="button" class="gl-it" data-id="' + it.id + '" title="' + esc(it.d || it.t) + '"><img src="' + galImg(it.id) + '" loading="lazy" alt="" onerror="this.parentNode.classList.add(\'nov\')"><span>' + esc(it.t) + '</span></button>';
      }).join('') + (list.length > max ? '<div class="gl-more">' + fa(list.length - max) + ' طرح دیگر — با جستجو یا انتخاب دسته محدودتر کنید.</div>' : '');
      none.hidden = list.length > 0;
      Array.prototype.forEach.call(grid.querySelectorAll('.gl-it'), function(b){ b.onclick = function(){ pick(byId[+b.getAttribute('data-id')]); }; });
    }
    ui = GalUI.mount(box.querySelector('.gl-side'), {
      cats: D.cats, items: D.items, side: true, noun: k === 'video' ? 'نمونه' : 'طرح',
      ai: function(q){ return api('gallery_ai', { value: k, message: q }); },
      onFilter: function(ids){ draw(ids); var c = box.closest ? box.closest('.fvc') : null; if (c) c.scrollTop = 0; }
    });
    draw(null);
    function pick(it){
      if (!it) return;
      var list = box.querySelector('.gl-list'), det = box.querySelector('.gl-det');
      var chain = [it.c].concat(it.c ? ui.anc(it.c) : []);
      var ps = D.presets.filter(function(p){ return !p.c.length || p.c.some(function(x){ return chain.indexOf(x) >= 0; }); }).map(function(p){ return { v: 'p' + p.id, id: p.id, t: p.t, d: p.d }; });
      if (it.pr) { var lp = D.presets.filter(function(p){ return p.id === it.pr; })[0]; if (lp) ps = [{ v: 'p' + lp.id, id: lp.id, t: lp.t, d: lp.d }].concat(ps.filter(function(p){ return p.id !== it.pr; })); }
      // اقدام آماده متصل به نمونه (نسخه ۵۲): اول و انتخاب‌شده
      var la = it.ac ? (D.actions || []).filter(function(a){ return a.id === it.ac; })[0] : null;
      if (la) ps = [{ v: 'a' + la.id, id: la.id, t: la.t, d: la.d, act: 1, ask: la.ask, hint: la.hint }].concat(ps);
      var selP = ps.length ? ps[0].v : '';
      det.innerHTML = '<button type="button" class="gl-back">→ بازگشت به گالری</button>'
        + '<div class="gl-pick"><img src="' + galImg(it.id, 1) + '" alt="" onerror="this.style.display=\'none\'"><div><b>' + esc(it.t) + '</b>' + (it.c ? '<p>📂 ' + esc(ui.path(it.c)) + '</p>' : '') + (it.d ? '<p>' + esc(it.d) + '</p>' : '') + '</div></div>'
        + '<div class="gl-lbl">۱) نوع اجرا</div>' + (ps.length ? ps.map(function(p){ return '<label class="gl-pr' + (p.v === selP ? ' on' : '') + '"><input type="radio" name="glp" value="' + p.v + '"' + (p.v === selP ? ' checked' : '') + '><span>' + esc(p.t) + (p.act ? ' <b style="font-size:11px;color:#b45309">⚡ اقدام آماده</b>' : '') + (p.d ? '<small>' + esc(p.d) + '</small>' : '') + '</span></label>'; }).join('') : '<p class="gl-none">برای این طرح نوع اجرایی تعریف نشده است.</p>')
        + '<div class="gl-lbl">۲) اطلاعات شما</div><textarea maxlength="1000" placeholder="' + (k === 'video' ? 'مثلاً: موضوع: افتتاحیه فروشگاه ما در شیراز، با حال‌وهوای شاد' : 'مثلاً: نام کسب‌وکار: کافه نارنج — شعار: طعم خاطره — رنگ دلخواه: نارنجی') + '"></textarea>'
        + '<div style="font-size:12px;color:var(--mut);margin-top:4px">متن‌هایی که باید در طرح بیاید را دقیق بنویسید. بعد از ساخت، در همان گفتگو می‌توانید تغییرات را بگویید.</div>'
        + (D.lock ? '<button type="button" class="btn btn-c gl-go gl-lock">🔒 ساخت از روی نمونه با ارتقای بسته</button>' : '<button type="button" class="btn btn-c gl-go">✨ ساخت ' + (k === 'video' ? 'ویدیو' : 'طرح') + ' (' + fa(D.cost) + ' پیام از سهمیه)</button>');
      list.hidden = true; det.hidden = false;
      var c = box.closest ? box.closest('.fvc') : null; if (c) c.scrollTop = 0;
      var ta = det.querySelector('textarea'), ph0 = ta.placeholder;
      function curP(){ return ps.filter(function(x){ return x.v === selP; })[0]; }
      function phSet(){ var c = curP(); ta.placeholder = c && c.act ? ((c.ask || c.hint) ? (c.ask ? c.ask + (c.hint ? ' — ' + c.hint : '') : c.hint) : 'اختیاری: توضیح اضافه') : ph0; }
      phSet();
      Array.prototype.forEach.call(det.querySelectorAll('.gl-pr input'), function(r){ r.onchange = function(){ selP = r.value; Array.prototype.forEach.call(det.querySelectorAll('.gl-pr'), function(x){ x.classList.toggle('on', x.contains(r)); }); phSet(); }; });
      det.querySelector('.gl-back').onclick = function(){ det.hidden = true; list.hidden = false; };
      det.querySelector('.gl-go').onclick = function(){
        if (D.lock) { closeView(true); showPromo(k); return; }
        var p = curP();
        if (!p) { alert('نوع اجرا را انتخاب کنید.'); return; }
        if (S.busy) { alert('صبر کنید تا پاسخ قبلی کامل شود.'); return; }
        var text = ta.value.trim();
        if (p.act && p.ask && !text) { alert('«' + p.ask + '» را بنویسید.'); ta.focus(); return; }
        closeAllViews(); try { history.back(); } catch (x) {}
        galRun(k, it, p, text);
      };
    }
  }
}
function galRun(kind, it, p, text){
  var snd = document.getElementById('snd');
  if (S.busy) return;
  S.busy = true; if (snd) snd.disabled = true; clearCtx();
  var shown = '🖼 از روی نمونه «' + it.t + '» — ' + p.t + (text ? ': ' + text : '');
  var gum = addMsg('u', shown);
  var tg = addMsg('a', ''), gprg = prgStart(tg.querySelector('.b'), kind === 'video' ? 'video' : 'image');
  api(kind === 'video' ? 'gen_video' : 'gen_image', { thread_id: S.tid || 0, message: text, sample_id: it.id, preset_id: p.act ? 0 : p.id, value: p.act ? p.id : 0, size: 'auto', model_id: gmGet(kind) }).then(function(r){
    var gsum = gprg.finish(r.ok); tg.remove(); S.busy = false; if (snd) snd.disabled = false;
    if (r.login_required) return loginRequired();
    if (!r.ok) { var em = addMsg('a', r.error || DOWN); if ((r.upgrade || r.can_buy) && C.canBuy && !X().imp) upBtn(em); if (r.quota) { S.quota = r.quota; renderQuota(); } return; }
    if (r.user_message_id) { gum.remove(); addMsg('u', r.user_text || shown, null, r.user_message_id); }
    var gam = addMsg('a', r.reply, null, r.message_id, 0, null, { media: r.media });
    prgAttach(gam, gsum); typeOut(gam);
    if (r.quota) { S.quota = r.quota; renderQuota(); }
    if (r.ext) { S.ext = r.ext; }
    if (!S.tid) S.tid = r.thread_id;
    loadThreads();
  });
}
function send(){
  var inp = document.getElementById('inp'), snd = document.getElementById('snd');
  cancelAutoSend();
  var text = inp.value.trim();
  if (S.busy) return;
  if (S.files.some(function(f){ return !f.id && !f.err; })) { alert('صبر کنید تا ارسال فایل‌ها تمام شود.'); return; }
  // ساخت تصویر / ویدیو
  if (S.gen) {
    if (S.act) { if (S.act.ask && !text) { alert('«' + S.act.ask + '» را بنویسید.'); return; } }
    else if (text.length < 2) { alert('توضیح کوتاهی از ' + (S.gen === 'video' ? 'ویدیوی' : 'تصویر') + ' مورد نظرتان بنویسید.'); return; }
    genRun(S.gen, text, S.gsize || 'auto');
    return;
  }
  var ready = S.files.filter(function(f){ return f.id; });
  if (!text && !ready.length) return;
  var editId = S.edit ? S.edit.id : 0, reply = S.reply, gsz2 = S.gsize || 'auto', remMode = !!S.remMode;
  S.busy = true; snd.disabled = true; inp.value = ''; inp.style.height = 'auto';
  var fids = ready.map(function(f){ return f.id; }), fshow = ready.map(function(f){ return { name: f.name, kind: f.kind }; });
  S.files = []; renderAtts(); clearCtx();
  if (editId) {
    // حذف پیام ویرایش‌شده و پیام‌های بعد از آن از صفحه
    var all = Array.prototype.slice.call(document.querySelectorAll('#wrap > *')), rm = false;
    all.forEach(function(el){ if (el.getAttribute('data-mid') == editId) rm = true; if (rm) el.remove(); });
  }
  var um = addMsg('u', text || '📎', null, null, null, fshow, { reply: reply ? { role: 'assistant', text: reply.text } : null });
  var t = addMsg('a', ''), prg = prgStart(t.querySelector('.b'), remMode ? 'rem' : prgKind(text, fids.length > 0, reply));
  api('send', { thread_id: S.tid || 0, message: text, file_ids: fids, model_id: X().model || 0, reply_to: reply ? reply.id : 0, edit_id: editId, size: gsz2, rem_mode: remMode ? 1 : 0 }).then(function(r){
    var psum = prg.finish(r.ok, r.reminder ? 'rem' : (r.media && r.media.length ? r.media[0].kind : 'text')); t.remove();
    if (r.login_required) { S.busy = false; return loginRequired(); }
    if (!r.ok && r.limited && r.quota) {
      // به سبک Claude: فقط نوار محدودیت کنار کادر تایپ؛ متن کاربر برمی‌گردد تا از دست نرود
      um.remove(); S.quota = r.quota; S.busy = false; renderQuota(); inp.value = text; inp.dispatchEvent(new Event('input'));
      if (editId) openThread(S.tid);
      return;
    }
    if (!r.ok) {
      var em = addMsg('a', r.error || DOWN);
      if (r.quota) { S.quota = r.quota; renderQuota(); }
      if ((r.can_buy || r.upgrade) && C.canBuy && !X().imp) upBtn(em);
      if (editId) openThread(S.tid);
    }
    else {
      if (r.user_message_id) { um.setAttribute('data-mid', r.user_message_id); um.remove(); addMsg('u', r.user_text || text || '📎', null, r.user_message_id, null, fshow, { reply: reply ? { role: 'assistant', text: reply.text } : null }); }
      var am = addMsg('a', r.reply, r.sources, r.message_id, 0, null, r.media ? { media: r.media } : null);
      prgAttach(am, psum);
      if (r.reminder) remCard(am, r.reminder);
      if (r.rem_ask) { var rab = am.querySelector('.b'); remAsk(rab, r.rem_ask, r.rem_ask.fields, function(f){
        f.leads = (f.leads || []).join(',');
        if (+f.ch_push) remPushAuto();
        api('rem_save', { rem: f }).then(function(x){
          var n = document.createElement('div'); n.style.cssText = 'margin-top:8px;font-size:13px;line-height:1.9';
          if (!x.ok) { n.style.color = '#b91c1c'; n.textContent = '⚠️ ' + (x.error || DOWN); rab.appendChild(n); return; }
          n.innerHTML = '✅ <b>ثبت شد:</b> ' + esc(x.item.icon + ' ' + x.item.title) + '<br>🕒 ' + esc(x.item.when) + (x.warn ? '<br><span style="color:#92400e">ℹ️ ' + esc(x.warn) + '</span>' : '');
          rab.appendChild(n); remCard(am, x.item); var rn = document.getElementById('remn'); if (rn) remStart();
        });
      }); }
      if (r.can_buy && C.canBuy && !X().imp) upBtn(am);
      typeOut(am);
      if (r.suggest) addSuggest(r.suggest);
      if (r.ad) addAd(r.ad);
      if (r.quota) { S.quota = r.quota; renderQuota(); }
      if (r.ext) { var keep = S.ext ? S.ext.model : 0; var before = JSON.stringify([X().files, X().voice, X().gen_image, X().gen_video]); S.ext = r.ext; if (keep && (r.ext.models || []).some(function(m){ return m.id == keep; })) S.ext.model = keep; renderModel(); if (before !== JSON.stringify([X().files, X().voice, X().gen_image, X().gen_video])) { var tid = S.tid || r.thread_id; S.tid = tid; viewChat(); openThread(tid); S.busy = false; return; } }
      if (!S.tid) { S.tid = r.thread_id; }
      loadThreads();
    }
    S.busy = false; snd.disabled = false; inp.focus();
  });
}
function upBtn(em){ var bb = document.createElement('button'); bb.className = 'btn btn-c'; bb.style.cssText = 'width:auto;margin-top:8px;font-size:13px;padding:7px 14px'; bb.textContent = '⭐ مشاهده و خرید بسته‌ها'; bb.onclick = function(){ showPlans(); }; em.querySelector('.b').appendChild(bb); }
// ---------- یادآورها ----------
function remMsg(box, t, k){ var m = box.querySelector('.rmsg'); if (!m) { m = document.createElement('div'); box.insertBefore(m, box.firstChild); } m.className = 'rmsg ' + (k || 'ok'); m.textContent = t;
  // پیام‌های مربوط به تمام شدن سهمیه: لینک خرید بسته
  if (C.canBuy && !X().imp && /بسته تهیه کنید|سهمیه پیامک/.test(t)) { var b = document.createElement('button'); b.type = 'button'; b.className = 'link'; b.style.cssText = 'font-weight:700;margin-right:6px'; b.textContent = '⭐ خرید بسته'; b.onclick = function(){ showPlans(); }; m.appendChild(b); } }
function remIcs(it){
  var d = new Date(it.ts * 1000), p = function(n){ return (n < 10 ? '0' : '') + n; }, z = function(x){ return x.getUTCFullYear() + p(x.getUTCMonth() + 1) + p(x.getUTCDate()) + 'T' + p(x.getUTCHours()) + p(x.getUTCMinutes()) + '00Z'; };
  var L = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//rem//fa','BEGIN:VEVENT','UID:rem' + it.id + '@' + location.host,'DTSTAMP:' + z(new Date()),'DTSTART:' + z(d),'DTEND:' + z(new Date(d.getTime() + 3600000)),'SUMMARY:' + it.title.replace(/[,;]/g, ' ')];
  if (it.place) L.push('LOCATION:' + it.place.replace(/[,;]/g, ' '));
  if (it.repeat === 'daily' || it.repeat === 'weekly') L.push('RRULE:FREQ=' + it.repeat.toUpperCase());
  (it.leads || [0]).forEach(function(l){ L.push('BEGIN:VALARM','ACTION:DISPLAY','DESCRIPTION:' + it.title.replace(/[,;]/g, ' '),'TRIGGER:-PT' + l + 'M','END:VALARM'); });
  L.push('END:VEVENT','END:VCALENDAR');
  var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([L.join('\r\n')], { type: 'text/calendar' })); a.download = 'reminder-' + it.id + '.ics'; document.body.appendChild(a); a.click(); a.remove();
}
function remCard(msgEl, it){
  var b = msgEl.querySelector('.b'); if (!b) return;
  var x = document.createElement('div'); x.className = 'rmx';
  x.innerHTML = '<button type="button" data-a="list">📋 یادآورهای من</button><button type="button" data-a="ics">📅 افزودن به تقویم گوشی</button>' + (!X().imp ? '<button type="button" data-a="push">🔔 اعلان روی گوشی</button>' : '') + '<button type="button" data-a="cancel">✖ لغو</button>';
  b.appendChild(x);
  x.querySelector('[data-a=list]').onclick = function(){ showRem(); };
  x.querySelector('[data-a=ics]').onclick = function(){ remIcs(it); };
  var pb = x.querySelector('[data-a=push]'); if (pb) pb.onclick = function(){ remPush(function(t, k){ pb.textContent = (k === 'er' || k === 'wr' ? '⚠️ ' : '✅ ') + t; }); };
  x.querySelector('[data-a=cancel]').onclick = function(){ var bt = this; if (!confirm('این یادآور لغو شود؟')) return; api('rem_act', { rem: { id: it.id, act: 'cancel' } }).then(function(r){ bt.textContent = r.ok ? '✔ لغو شد' : (r.error || DOWN); bt.disabled = true; }); };
}
function remB64(s){ s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a; }
function remPush(done){
  var inFrame = window.top !== window.self;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !C.vapid) return done(inFrame ? 'برای اعلان، چت را در صفحه کامل باز کنید.' : 'این مرورگر از اعلان پشتیبانی نمی‌کند (آیفون: سایت را به صفحه اصلی اضافه کنید).', 'wr');
  if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') return done('اعلان فقط روی آدرس امن (https) کار می‌کند.', 'wr');
  Notification.requestPermission().then(function(p){
    if (p !== 'granted') return done(inFrame ? 'مرورگر اجازه نداد؛ چت را در صفحه کامل باز کنید و دوباره بزنید.' : 'اجازه اعلان داده نشد (از آیکون قفل کنار آدرس اجازه دهید).', 'wr');
    return navigator.serviceWorker.register(C.sw).then(function(){ return navigator.serviceWorker.ready; }).then(function(reg){
      return reg.pushManager.getSubscription().then(function(s){ return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: remB64(C.vapid) }); });
    }).then(function(sub){ return api('push_sub', { rem: { sub: sub.toJSON(), ua: navigator.userAgent.slice(0, 180) } }); })
      .then(function(r){ done(r && r.ok ? 'اعلان روی این دستگاه فعال شد' : ((r && r.error) || 'فعال‌سازی ممکن نشد'), r && r.ok ? 'ok' : 'er'); });
  }).catch(function(e){ done('فعال‌سازی اعلان ممکن نشد', 'er'); });
}
var REMSHOWN = {};
function remToast(e){
  if (REMSHOWN[e.id]) return; REMSHOWN[e.id] = 1;
  var box = document.querySelector('.rmt'); if (!box) { box = document.createElement('div'); box.className = 'rmt'; document.body.appendChild(box); }
  var d = document.createElement('div'); d.className = 'tt' + (e.high ? ' hi' : '');
  d.innerHTML = '<b>' + esc(e.title) + '</b><p>' + esc(e.body) + '</p><div>' + (e.active ? '<button class="k" data-a="done">✔ ' + (e.repeat ? 'این نوبت انجام شد' : 'انجام شد') + '</button><button data-a="snooze" data-g="10">⏰ ۱۰ دقیقه</button><button data-a="snooze" data-g="60">⏰ ۱ ساعت</button>' : '') + '<button data-a="">✕</button></div>';
  Array.prototype.forEach.call(d.querySelectorAll('button'), function(b){ b.onclick = function(){ api('rem_ack', { rem: { id: e.id, act: b.getAttribute('data-a'), arg: +(b.getAttribute('data-g') || 0) } }); d.remove(); }; });
  box.appendChild(d);
  try { if (document.hidden && window.Notification && Notification.permission === 'granted') new Notification(e.title, { body: e.body, dir: 'rtl' }); } catch (x) {}
}
function remPoll(){
  if (!C.rem || X().imp || !document.getElementById('side')) return;
  api('rem_due').then(function(r){ if (r && r.ok) (r.items || []).slice().reverse().forEach(remToast); });
}
function remStart(){
  if (!C.rem || X().imp) return;
  if (!S.remTimer) { S.remTimer = setInterval(remPoll, 60000); }
  setTimeout(remPoll, 1200);
  api('rem_list', { rem: { scope: 'today' } }).then(function(r){ var n = r && r.ok ? r.meta.counts.today + r.meta.counts.overdue : 0; var el = document.getElementById('remn'); if (el && n) { el.textContent = fa(n); el.style.display = ''; } });
  if (C.openRem) { C.openRem = false; setTimeout(showRem, 300); }
}
/** صبح یا عصر؟ — دکمه‌ها؛ done(fields) با ساعت و تاریخ انتخاب‌شده */
function remAsk(host, ask, fields, done){
  var d = document.createElement('div'); d.className = 'rask';
  d.innerHTML = '<p>🕒 ' + esc(ask.q) + '</p><div class="ob">' + ask.opts.map(function(o, i){ return '<button type="button" data-i="' + i + '">' + esc(o.label) + '<small>' + esc(o.when) + '</small></button>'; }).join('') + '<button type="button" class="x" data-i="x">انصراف</button></div>';
  host.appendChild(d);
  Array.prototype.forEach.call(d.querySelectorAll('button'), function(b){ b.onclick = function(){
    var i = b.getAttribute('data-i'); d.remove(); if (i === 'x') return;
    var o = ask.opts[+i], f = {}; for (var k in fields) f[k] = fields[k]; f.time = o.time; f.jdate = o.jdate; f.all_day = 0; done(f);
  }; });
  try { d.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {}
}
/** هنگام ثبت یادآور با «اعلان گوشی»: اگر هنوز اجازه داده نشده، همان لحظه پرسیده می‌شود (حتی وقتی سایت بسته است اعلان می‌رسد) */
function remPushAuto(){
  try { if (C.rem && C.vapid && window.Notification && Notification.permission === 'default' && ('serviceWorker' in navigator)) remPush(function(){}); } catch (e) {}
}
function remPushTest(cb){
  if (!('serviceWorker' in navigator)) return cb('این مرورگر از اعلان پشتیبانی نمی‌کند.', 'wr');
  navigator.serviceWorker.getRegistration(C.sw).then(function(reg){ return reg ? reg.pushManager.getSubscription() : null; }).then(function(sub){
    if (!sub) return cb('اول «🔔 اعلان روی گوشی» را فعال کنید.', 'wr');
    api('push_test', { rem: { endpoint: sub.endpoint } }).then(function(r){ cb(r.ok ? '📨 ' + r.msg : '⚠️ ' + (r.error || DOWN), r.ok ? 'ok' : 'er'); });
  }).catch(function(){ cb('ارسال آزمایشی ممکن نشد.', 'er'); });
}
function showRem(){
  var box = openView('⏰ یادآورها و برنامه‌ها'), M = null, scope = 'upcoming';
  box.innerHTML = '<div class="card"><div style="font-weight:800;margin-bottom:6px">✨ فقط بنویسید</div><textarea id="rst" class="inpt" rows="2" placeholder="مثلاً: فردا ساعت ۱۰ جلسه با آقای احمدی، یک ساعت قبل خبرم کن"></textarea>'
    + '<div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;align-items:center"><button class="btn btn-c" id="rq" style="width:auto;padding:7px 14px">⚡ ثبت</button><button class="link" id="rp">🔎 بررسی و ویرایش</button><button class="link" id="rn">➕ فرم کامل</button><span style="margin-right:auto;font-size:11.5px;color:var(--mut)" id="rinfo"></span></div></div>'
    + '<div class="card rform" id="rf" style="display:none"></div>'
    + '<div class="rtabs"><button class="on" data-s="upcoming">📋 پیش رو <b></b></button><button data-s="today">☀️ امروز <b></b></button><button data-s="overdue">⚠️ موعد گذشته <b></b></button><button data-s="done">✔ انجام‌شده</button><button id="rpush" style="margin-right:auto">🔔 اعلان روی گوشی</button><button id="rtest" title="ارسال اعلان آزمایشی به همین دستگاه">📨 آزمایش</button></div><div id="rl">' + loadingHtml() + '</div>';
  var st = box.querySelector('#rst');
  function info(){ if (!M) return; var t = []; t.push('📩 پیامک یادآور: ' + fa(M.sms_left)); if (M.devices) t.push('🔔 اعلان فعال روی ' + fa(M.devices) + ' دستگاه'); box.querySelector('#rinfo').innerHTML = esc(t.join(' — ')) + (C.canBuy && !M.sms_left ? ' <button class="link" id="rbuy" style="font-size:11.5px">خرید پیامک</button>' : ''); var rb = box.querySelector('#rbuy'); if (rb) rb.onclick = function(){ showPlans(); };
    var c = M.counts, bs = box.querySelectorAll('.rtabs b'); bs[0].textContent = fa(c.upcoming); bs[1].textContent = fa(c.today); bs[2].textContent = fa(c.overdue);
    var pb = box.querySelector('#rpush'); if (M.devices) pb.textContent = '🔔 اعلان فعال است'; }
  function list(){
    var el = box.querySelector('#rl'); el.innerHTML = loadingHtml();
    api('rem_list', { rem: { scope: scope } }).then(function(r){
      if (!r.ok) { el.innerHTML = '<p style="color:#b91c1c">' + esc(r.error || DOWN) + '</p>'; return; }
      M = r.meta; info();
      if (!r.items.length) { el.innerHTML = '<p style="text-align:center;color:var(--mut);padding:18px">' + ({ upcoming: 'یادآور پیش رویی ندارید. بالا یک جمله بنویسید ✨', today: 'امروز یادآوری ندارید ☀️', overdue: 'موردی عقب نیفتاده 👌', done: 'چیزی انجام‌شده نیست.' }[scope]) + '</p>'; return; }
      var h = '', last = '';
      r.items.forEach(function(it){
        var day = it.when.split('،')[0]; if (scope !== 'done' && day !== last) { h += '<div class="rday">' + esc(day) + '</div>'; last = day; }
        var bg = []; if (it.repeat_text) bg.push('🔁 ' + it.repeat_text); if (it.leads_text) bg.push('🔔 ' + it.leads_text); bg.push((it.ch_push ? '📱' : '') + (it.ch_sms ? '📩' : '') + (it.ch_site ? '🖥' : '')); if (it.from) bg.push('از طرف: ' + it.from); if (it.snooze) bg.push('⏰ تا ' + it.snooze);
        h += '<div class="rit' + (it.overdue ? ' od' : '') + (it.status !== 'active' ? ' dn' : '') + '" style="--cc:' + it.color + '" data-id="' + it.id + '"><div class="ic">' + it.icon + '</div><div class="bd"><div class="t">' + (it.priority ? '❗ ' : '') + esc(it.title) + '</div>'
          + '<div class="w">🕒 ' + esc(it.when) + (it.person ? ' — 👤 ' + esc(it.person) : '') + (it.place ? ' — 📍 ' + esc(it.place) : '') + '</div><div class="bg">' + bg.filter(Boolean).map(function(x){ return '<span>' + esc(x) + '</span>'; }).join('') + '</div>'
          + (it.note ? '<div class="w">' + esc(it.note) + '</div>' : '') + '<div class="ab">'
          + (it.status === 'active' ? '<button class="k" data-a="done">✔ انجام شد</button><button data-a="snooze" data-g="60">⏰ ۱ ساعت بعد</button><button data-a="snooze" data-g="1440">⏰ فردا</button>' : (it.can_edit ? '<button data-a="reopen">↺ فعال‌سازی</button>' : ''))
          + (it.can_edit ? '<button data-a="edit">✏️</button>' : '') + '<button data-a="ics">📅</button>' + (it.can_edit ? '<button data-a="delete">🗑</button>' : '') + '</div></div></div>';
      });
      el.innerHTML = h;
      Array.prototype.forEach.call(el.querySelectorAll('.rit'), function(row){
        var it = r.items.filter(function(x){ return x.id == row.getAttribute('data-id'); })[0];
        Array.prototype.forEach.call(row.querySelectorAll('[data-a]'), function(b){ b.onclick = function(){
          var a = b.getAttribute('data-a');
          if (a === 'edit') return form(it);
          if (a === 'ics') return remIcs(it);
          if (a === 'delete' && !confirm('حذف شود؟')) return;
          api('rem_act', { rem: { id: it.id, act: a, arg: +(b.getAttribute('data-g') || 0) } }).then(function(x){ remMsg(box, x.ok ? (x.msg || 'انجام شد') : (x.error || DOWN), x.ok ? 'ok' : 'er'); list(); });
        }; });
      });
    });
  }
  function form(f){
    f = f || {}; var el = box.querySelector('#rf'); el.style.display = '';
    var cats = (M && M.cats) || {}, leads = (M && M.leads) || {}, reps = (M && M.repeats) || {}, days = (M && M.days) || [];
    var pref = (M && M.pref) || 'site,push', isNew = !f.id && f.ch_push == null;
    el.innerHTML = '<div style="font-weight:800">' + (f.id ? '✏️ ویرایش یادآور' : '🔎 یادآور') + '</div>'
      + '<label class="l">عنوان</label><input class="inpt" name="title" maxlength="200">'
      + '<label class="l">دسته</label><select class="inpt" name="category">' + Object.keys(cats).map(function(k){ return '<option value="' + k + '">' + cats[k][1] + ' ' + esc(cats[k][0]) + '</option>'; }).join('') + '</select>'
      + '<div class="rw"><div><label class="l">تاریخ (شمسی)</label><input class="inpt" name="jdate" dir="ltr" placeholder="1405/07/15"><div class="rqd">' + days.map(function(d){ return '<button type="button" data-j="' + d[0] + '">' + esc(d[1]) + '</button>'; }).join('') + '</div></div>'
      + '<div><label class="l">ساعت</label><input class="inpt" type="time" name="time" dir="ltr"><label style="font-size:11.5px"><input type="checkbox" name="all_day"> تمام روز</label></div></div>'
      + '<label class="l">چه زمانی خبرم کنی؟</label><div class="rchk">' + Object.keys(leads).map(function(k){ return '<label><input type="checkbox" name="leads" value="' + k + '"' + (k === '0' ? ' checked disabled' : '') + '> ' + esc(leads[k]) + '</label>'; }).join('') + '</div>'
      + '<div class="rw"><div><label class="l">تکرار</label><select class="inpt" name="repeat">' + Object.keys(reps).map(function(k){ return '<option value="' + k + '">' + esc(reps[k]) + '</option>'; }).join('') + '</select></div>'
      + '<div><label class="l">با چه کسی / کجا</label><input class="inpt" name="person" placeholder="با …"><input class="inpt" name="place" placeholder="کجا …" style="margin-top:4px"></div></div>'
      + '<label class="l">روش اطلاع</label><div class="rchk"><label><input type="checkbox" name="ch_site" checked disabled> 🖥 همین صفحه</label><label><input type="checkbox" name="ch_push"> 📱 اعلان گوشی</label><label><input type="checkbox" name="ch_sms"> 📩 پیامک (' + fa((M && M.sms_left) || 0) + ' مانده)</label></div>'
      + '<label class="l">توضیح</label><textarea class="inpt" name="note" rows="2"></textarea>'
      + '<div style="display:flex;gap:6px;margin-top:10px"><button class="btn btn-c" id="rsv" style="width:auto;padding:7px 16px">💾 ذخیره</button><button class="link" id="rcx">انصراف</button></div>';
    var q = function(n){ return el.querySelector('[name=' + n + ']'); };
    q('title').value = f.title || ''; q('category').value = f.category || 'task'; q('jdate').value = f.jdate || (M ? M.today_j : ''); q('time').value = f.all_day ? '' : (f.time || ''); q('all_day').checked = !!f.all_day;
    Array.prototype.forEach.call(el.querySelectorAll('[name=leads]'), function(c){ c.checked = c.value === '0' || (f.leads || []).map(String).indexOf(c.value) >= 0; });
    q('repeat').value = f.repeat || 'none'; q('person').value = f.person || ''; q('place').value = f.place || ''; q('note').value = f.note || '';
    q('ch_push').checked = isNew ? pref.indexOf('push') >= 0 : !!f.ch_push; q('ch_sms').checked = isNew ? pref.indexOf('sms') >= 0 : !!f.ch_sms;
    Array.prototype.forEach.call(el.querySelectorAll('.rqd button'), function(b){ b.onclick = function(){ q('jdate').value = b.getAttribute('data-j'); }; });
    el.querySelector('#rcx').onclick = function(){ el.style.display = 'none'; };
    el.querySelector('#rsv').onclick = function(){
      var bt = this; bt.disabled = true;
      if (q('ch_push').checked) remPushAuto();
      var data = { id: f.id || 0, title: q('title').value, category: q('category').value, jdate: q('jdate').value, time: q('time').value, all_day: q('all_day').checked ? 1 : 0, repeat: q('repeat').value,
        person: q('person').value, place: q('place').value, note: q('note').value, ch_site: 1, ch_push: q('ch_push').checked ? 1 : 0, ch_sms: q('ch_sms').checked ? 1 : 0, source: f.source || 'form', raw_text: f.raw_text || '',
        leads: Array.prototype.filter.call(el.querySelectorAll('[name=leads]'), function(c){ return c.checked; }).map(function(c){ return +c.value; }) };
      api('rem_save', { rem: data }).then(function(r){ bt.disabled = false; if (!r.ok) return remMsg(box, r.error || DOWN, 'er'); el.style.display = 'none'; remMsg(box, '✅ ذخیره شد: ' + r.item.title + ' — ' + r.item.when + (r.warn ? ' — ' + r.warn : ''), r.warn ? 'wr' : 'ok'); list(); });
    };
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function smart(save, btn){
    var t = st.value.trim(); if (t.length < 4) return remMsg(box, 'یک جمله بنویسید؛ مثلاً «فردا ساعت ۱۰ جلسه با آقای احمدی».', 'er');
    btn.disabled = true;
    api('rem_parse', { rem: { text: t, save: save ? 1 : 0 } }).then(function(r){
      btn.disabled = false;
      if (!r.ok) return remMsg(box, r.error || DOWN, 'er');
      st.value = '';
      if (r.ask) return remAsk(box.querySelector('.card'), r.ask, r.fields, function(f){
        if (!r.save) return form(f);
        f.source = 'smart'; f.leads = (f.leads || []).join(',');
        api('rem_save', { rem: f }).then(function(x){ if (!x.ok) return remMsg(box, x.error || DOWN, 'er'); remMsg(box, '✅ ثبت شد: ' + x.item.icon + ' ' + x.item.title + ' — ' + x.item.when + (x.warn ? ' — ' + x.warn : ''), x.warn ? 'wr' : 'ok'); M = x.meta || M; list(); });
      });
      if (r.saved) { remMsg(box, '✅ ثبت شد: ' + r.item.icon + ' ' + r.item.title + ' — ' + r.item.when + (r.warn ? ' — ' + r.warn : ''), r.warn ? 'wr' : 'ok'); M = r.meta || M; list(); return; }
      form(r.fields);
    });
  }
  box.querySelector('#rq').onclick = function(){ remPushAuto(); smart(true, this); };
  box.querySelector('#rp').onclick = function(){ smart(false, this); };
  box.querySelector('#rn').onclick = function(){ form({}); };
  st.onkeydown = function(ev){ if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); smart(true, box.querySelector('#rq')); } };
  box.querySelector('#rtest').onclick = function(){ var b = this; b.disabled = true; remPushTest(function(t, k){ b.disabled = false; remMsg(box, t, k); }); };
  box.querySelector('#rpush').onclick = function(){ var b = this; remPush(function(t, k){ remMsg(box, t, k); if (k === 'ok') { b.textContent = '🔔 اعلان فعال است'; list(); } }); };
  Array.prototype.forEach.call(box.querySelectorAll('.rtabs [data-s]'), function(b){ b.onclick = function(){ scope = b.getAttribute('data-s'); Array.prototype.forEach.call(box.querySelectorAll('.rtabs [data-s]'), function(x){ x.classList.toggle('on', x === b); }); list(); }; });
  list();
}
function loginRequired(){
  S.busy = false;
  if (S.via) { viewLogin('form', '', 'برای استفاده از «' + C.name + '» وارد شوید.'); return; }
  viewLogin('form');
}

function start(){
  if (location.hash.indexOf('#g=') === 0) {
    var gh = decodeURIComponent(location.hash.slice(3)), gi = gh.indexOf(':');
    history.replaceState(null, '', location.pathname + location.search);
    gResult(gi < 0 ? gh : gh.slice(0, gi), gi < 0 ? '' : gh.slice(gi + 1));
    return;
  }
  if (!C.available) { app.innerHTML = '<div class="main"><div class="off">' + avatar() + '<br>' + esc(C.name) + '<br>' + 'در حال حاضر این سرویس در دسترس نیست. لطفاً بعداً مراجعه کنید.' + (C.hub ? '<br><button class="link" id="hubx">🤖 سایر چت‌بات‌ها</button>' : '') + '</div></div>'; var hx = document.getElementById('hubx'); if (hx) hx.onclick = showHub; return; }
  api('me').then(function(r){
    if (r.login_required || !r.ok) { var ge = S.gErr; S.gErr = ''; if (!r.login_required && r.error) { viewLogin('form', r.error); return; } viewLogin('form', ge || C.payMsg || ''); return; }
    S.name = r.name; S.quota = r.quota; S.ext = r.ext || null; viewChat();
    var hp = /^#plan=(\d+)$/.exec(location.hash || ''); if (hp && C.canBuy) { S.focusPlan = +hp[1]; showPlans(); }
  });
}
(function boot(){
  var sb = C.startBot;
  if (!sb) { try { sb = +(sessionStorage.getItem('hdc_via') || 0); } catch (x) {} }
  if (C.hub && sb && sb !== C.home) switchBot(sb);
  else start();
  if (C.showHub && C.hub) showHub();   // لینک صفحه همه چت‌بات‌ها: ?bots=1
})();
})();
</script>
</body>
</html>
