<?php
/**
 * لایه امنیتی مشترک (بدون نیاز به دیتابیس؛ قبل از هر چیز در صفحه‌ها بارگذاری می‌شود)
 *
 *  - sec_session_start(): کوکی نشست امن (HttpOnly، Secure روی https، SameSite=Lax، strict mode)
 *  - sec_boot($area): رد آدرس‌های «PATH_INFO» (مثل users.php/login.php)، فیلتر حمله‌های رایج (WAF سبک)،
 *    مسدودسازی ابزارهای اسکن، محدودیت تعداد درخواست هر IP، هدرهای امنیتی (HSTS، CSP پایه، ...)
 *  - sec_csrf_check(): جلوگیری از جعل درخواست (CSRF) در پنل‌ها: توکن یا هم‌مبدأ بودن Origin/Referer
 *  - sec_get_guard(): عملیات حذف/تغییر با لینک (GET) فقط از داخل خود پنل
 *  - sec_form_guard(): تله ربات (فیلد مخفی + زمان پر کردن فرم) برای فرم‌های ورود/ثبت‌نام/بازیابی
 *  - sec_rate(): شمارنده سبک مبتنی بر فایل برای محدودیت درخواست
 *  - sec_once(): نشانه یک‌بارمصرف (مثلاً کپچا)
 *  - sec_url_safe(): جلوگیری از درخواست سرور به آدرس‌های داخلی (SSRF)
 *
 * تنظیمات اختیاری (در محیط آزمایش): SEC_BOT_GUARD_OFF، SEC_RATE_OFF، SEC_FORM_MIN_SECONDS
 */

if (!defined('SEC_LOADED')) {
define('SEC_LOADED', 1);

function sec_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return false;
}

function sec_ip()
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** پوشه خصوصی ذخیره شمارنده‌ها (داخل uploads، با .htaccess دسترسی بسته) */
function sec_dir()
{
    static $d = null;
    if ($d !== null) return $d;
    $base = defined('SEC_DIR_BASE') ? SEC_DIR_BASE : dirname(__DIR__) . '/uploads';   // همیشه یک مسیر ثابت (مستقل از ترتیب بارگذاری config)
    $d = $base . '/.sec';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($d . '/index.html', '');
    }
    if (!is_dir($d) || !is_writable($d)) $d = rtrim(sys_get_temp_dir(), '/') . '/aichat_sec_' . substr(md5(__DIR__), 0, 8);
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** کلید مخفی برنامه (یک بار ساخته می‌شود؛ همراه رمز دیتابیس) */
function sec_secret()
{
    // مهم: نباید به ترتیب بارگذاری config.php وابسته باشد؛ در صفحه ورود، بررسی فرم پیش از بارگذاری config انجام می‌شود
    // (قبلاً رمز دیتابیس در کلید بود و چون هنگام بررسی هنوز تعریف نشده بود، امضای فرم همیشه نامعتبر می‌شد).
    static $k = null;
    if ($k !== null) return $k;
    $f = sec_dir() . '/.key';
    $raw = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if (strlen($raw) < 32) {
        $new = bin2hex(random_bytes(32));
        if (@file_put_contents($f, $new, LOCK_EX) === strlen($new)) { @chmod($f, 0600); $raw = $new; }
        else {
            // پوشه uploads قابل نوشتن نیست: کلید ثابت از محتوای config.php (محرمانه و در همه درخواست‌ها یکسان)
            error_log('[SEC] cannot write ' . $f . ' — using config-derived key; make uploads/ writable');
            $cf = dirname(__DIR__) . '/config.php';
            $raw = 'cfg|' . (is_file($cf) ? (string)@hash_file('sha256', $cf) : '') . '|' . __FILE__;
        }
    }
    return $k = hash('sha256', $raw . '|' . __DIR__);
}

/** شمارنده پنجره ثابت؛ true یعنی از سقف گذشته است */
function sec_rate($bucket, $limit, $window = 60, $ip = null)
{
    if (defined('SEC_RATE_OFF')) return false;
    $ip = $ip ?? sec_ip();
    $slot = (int)floor(time() / max(1, (int)$window));
    $f = sec_dir() . '/r_' . substr(sha1($bucket . '|' . $ip), 0, 24);
    $fp = @fopen($f, 'c+');
    if (!$fp) return false;
    $over = false;
    if (@flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $d = $raw ? json_decode($raw, true) : null;
        if (!is_array($d) || (int)($d['s'] ?? -1) !== $slot) $d = ['s' => $slot, 'n' => 0];
        $d['n']++;
        $over = $d['n'] > (int)$limit;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d));
        fflush($fp); flock($fp, LOCK_UN);
    }
    fclose($fp);
    if (mt_rand(1, 500) === 1) sec_cleanup();
    return $over;
}

/** نشانه یک‌بارمصرف: بار اول true، تکرار false */
function sec_once($kind, $id, $ttl = 3600)
{
    $f = sec_dir() . '/o_' . substr(sha1($kind . '|' . $id), 0, 32);
    if (is_file($f) && filemtime($f) > time() - $ttl) return false;
    $fp = @fopen($f, 'x');
    if ($fp === false) {
        if (is_file($f) && filemtime($f) <= time() - $ttl) { @unlink($f); $fp = @fopen($f, 'x'); }
        if ($fp === false) return false;
    }
    fclose($fp);
    return true;
}

function sec_cleanup()
{
    $now = time();
    foreach (glob(sec_dir() . '/{r_,o_}*', GLOB_BRACE) ?: [] as $f) if (@filemtime($f) < $now - 7200) @unlink($f);
}

function sec_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (!headers_sent()) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        $p = session_get_cookie_params();
        session_set_cookie_params(['lifetime' => 0, 'path' => $p['path'] ?: '/', 'domain' => $p['domain'] ?? '', 'secure' => sec_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    @session_start();
}

/** پاسخ خطا و توقف */
function sec_deny($code, $msg)
{
    if (!headers_sent()) {
        http_response_code($code);
        if ($code === 429) header('Retry-After: 60');
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    $ajax = !empty($_SERVER['HTTP_X_PG_AJAX']) || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    if ($ajax) { echo $msg; exit; }
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>دسترسی محدود</title>'
       . '<style>body{font-family:Tahoma,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px;box-sizing:border-box}.b{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px;max-width:440px;text-align:center;line-height:1.9;color:#334155}a{color:#2563eb}</style></head>'
       . '<body><div class="b"><div style="font-size:34px">🛡️</div><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a href="javascript:history.back()">بازگشت</a></p></div></body></html>';
    exit;
}

/** فیلتر سبک حمله‌ها روی آدرس (نه متن پیام‌ها) */
function sec_waf_bad_uri()
{
    $u = (string)($_SERVER['REQUEST_URI'] ?? '');
    $d = strtolower(rawurldecode(rawurldecode($u)));
    if (strpos($d, "\0") !== false) return 'null byte';
    $pat = [
        '/\bunion\b[\s\/\*\(\)+]+(all[\s\/\*]+)?select\b/', '/\binformation_schema\b/', '/\b(sleep|benchmark)\s*\(\s*\d/', '/\bload_file\s*\(/',
        '/\binto\s+(out|dump)file\b/', '/\.\.(\/|\\\\)/', '/<\s*script\b/', '/\bjavascript\s*:/', '/\/etc\/passwd\b/', '/\/proc\/self\//',
        '/\bphp:\/\/(input|filter)/', '/\b(base64_decode|eval|assert|system|shell_exec|passthru)\s*\(/', '/\bwaitfor\s+delay\b/', '/(\'|")\s*or\s+\d+\s*=\s*\d+/',
    ];
    foreach ($pat as $p) if (preg_match($p, $d)) return $p;
    return '';
}

function sec_bad_agent()
{
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return 'empty';
    foreach (['sqlmap', 'nikto', 'acunetix', 'nessus', 'nmap', 'masscan', 'wpscan', 'dirbuster', 'gobuster', 'feroxbuster', 'ffuf', 'zgrab', 'nuclei', 'havij', 'w3af', 'openvas', 'netsparker', 'jaeles', 'whatweb', 'commix', 'arachni', 'httrack'] as $b)
        if (strpos($ua, $b) !== false) return $b;
    return '';
}

function sec_log($what)
{
    @file_put_contents(sec_dir() . '/attack.log', date('Y-m-d H:i:s') . ' ' . sec_ip() . ' ' . substr(preg_replace('/\s+/', ' ', $what), 0, 300) . "\n", FILE_APPEND | LOCK_EX);
    $f = sec_dir() . '/attack.log';
    if (@filesize($f) > 2 * 1024 * 1024) @rename($f, $f . '.1');
}

/** هدرهای امنیتی */
function sec_headers($frame = 'SAMEORIGIN')
{
    if (headers_sent()) return;
    if ($frame) header('X-Frame-Options: ' . $frame);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), geolocation=(), payment=(), usb=(), microphone=(self)');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Content-Security-Policy: ' . ($frame ? "frame-ancestors 'self'; " : '') . "object-src 'none'; base-uri 'self'");
    if (sec_is_https()) header('Strict-Transport-Security: max-age=31536000');
    header_remove('X-Powered-By');
}

/**
 * شروع امن هر صفحه
 * $area: admin | user | public | auth (صفحه‌های ورود/ثبت‌نام)
 */
function sec_boot($area = 'public', $session = true)
{
    static $done = false;
    if ($done) return;
    $done = true;
    // ۱) آدرس‌های دارای مسیر اضافه بعد از .php (مثل /admin/users.php/login.php) پذیرفته نمی‌شوند
    $path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if (preg_match('#\.php[^/]*/#i', $path) || (string)($_SERVER['PATH_INFO'] ?? '') !== '') sec_deny(404, 'صفحه پیدا نشد.');
    // ۲) ابزارهای اسکن و الگوهای حمله در آدرس
    if (!defined('SEC_BOT_GUARD_OFF')) {
        $ba = sec_bad_agent();
        if ($ba !== '' && $ba !== 'empty') { sec_log('agent ' . $ba); sec_deny(403, 'دسترسی مجاز نیست.'); }
        if ($ba === 'empty' && in_array($area, ['admin', 'user', 'auth'], true)) { sec_log('empty agent'); sec_deny(403, 'دسترسی مجاز نیست.'); }
        $w = sec_waf_bad_uri();
        if ($w !== '') { sec_log('waf ' . $w . ' ' . ($_SERVER['REQUEST_URI'] ?? '')); sec_deny(403, 'درخواست نامعتبر است.'); }
    }
    // ۳) سقف درخواست هر IP (جلوگیری از حمله رباتی و شمارش رمز)
    $lim = ['admin' => 600, 'user' => 600, 'public' => 600, 'auth' => 60][$area] ?? 600;
    if (sec_rate('p_' . $area, $lim, 60)) { sec_log('rate ' . $area); sec_deny(429, 'تعداد درخواست‌ها بیش از حد است؛ یک دقیقه دیگر دوباره تلاش کنید.'); }
    if ($area === 'auth' && $_SERVER['REQUEST_METHOD'] === 'POST' && sec_rate('auth_post', 120, 3600)) { sec_log('auth flood'); sec_deny(429, 'تلاش‌های زیادی از این اینترنت انجام شده؛ لطفاً بعداً دوباره تلاش کنید.'); }
    sec_headers();
    if ($session) sec_session_start();
}

// ─────────────────────────────── CSRF ───────────────────────────────
function sec_csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}

function sec_host_of($url)
{
    $h = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
    $p = parse_url((string)$url, PHP_URL_PORT);
    return $h === '' ? '' : $h . ($p ? ':' . $p : '');
}

function sec_my_hosts()
{
    $hosts = [];
    $hh = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($hh !== '') { $hosts[] = $hh; $hosts[] = preg_replace('/:(80|443)$/', '', $hh); }
    if (defined('AICHAT_BASE_URL')) $hosts[] = sec_host_of(AICHAT_BASE_URL);
    return array_values(array_unique(array_filter($hosts)));
}

/** آیا درخواست از همین سایت آمده؟ (true/false، یا null اگر هیچ نشانه‌ای نیست) */
function sec_same_origin()
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o !== '' && $o !== 'null') return in_array(sec_host_of($o), sec_my_hosts(), true) || in_array(preg_replace('/:(80|443)$/', '', sec_host_of($o)), sec_my_hosts(), true);
    $r = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($r !== '') return in_array(sec_host_of($r), sec_my_hosts(), true) || in_array(preg_replace('/:(80|443)$/', '', sec_host_of($r)), sec_my_hosts(), true);
    return null;
}

/**
 * بررسی CSRF برای همه درخواست‌های POST پنل
 * معتبر: توکن درست (فیلد csrf_token/csrf/_csrf یا هدر X-CSRF-Token) یا Origin/Referer همین سایت.
 */
function sec_csrf_check()
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $tok = sec_csrf_token();
    foreach ([$_POST['csrf_token'] ?? null, $_POST['_csrf'] ?? null, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null] as $t)
        if (is_string($t) && $tok !== '' && hash_equals($tok, $t)) return;
    // توکن‌های اختصاصی صفحه‌ها (یادآورها، همکاران)
    foreach (['rem_csrf' => 'csrf', 'sw_tok' => 'tok'] as $sk => $pf)
        if (!empty($_SESSION[$sk]) && is_string($_POST[$pf] ?? null) && hash_equals((string)$_SESSION[$sk], (string)$_POST[$pf])) return;
    $same = sec_same_origin();
    if ($same === true) return;
    sec_log('csrf ' . ($_SERVER['REQUEST_URI'] ?? '') . ' origin=' . ($_SERVER['HTTP_ORIGIN'] ?? '-') . ' ref=' . ($_SERVER['HTTP_REFERER'] ?? '-'));
    sec_deny(403, 'درخواست از مبدأ نامعتبر رسید و برای امنیت حساب شما انجام نشد. صفحه را تازه کنید و دوباره تلاش کنید.');
}

/** عملیات تغییر/حذف با لینک (GET) فقط وقتی از داخل همین سایت کلیک شده باشد */
function sec_get_guard()
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $g = $_GET;
    $risky = false;
    foreach (['del', 'delete', 'toggle', 'pin', 'remove', 'ban', 'unblock', 'purge'] as $k) if (isset($g[$k])) $risky = true;
    if (isset($g['action']) && in_array(strtolower((string)$g['action']), ['delete', 'del', 'remove', 'toggle'], true)) $risky = true;
    if (isset($g['status'], $g['uid'])) $risky = true;
    if (!$risky) return;
    $t = (string)($g['_t'] ?? '');
    if ($t !== '' && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t)) return;
    if (sec_same_origin() === true) return;
    sec_log('get-guard ' . ($_SERVER['REQUEST_URI'] ?? ''));
    sec_deny(403, 'برای امنیت، این عملیات فقط از داخل پنل قابل انجام است. به پنل برگردید و دوباره روی دکمه بزنید.');
}

/** اسکریپت: افزودن خودکار توکن به همه فرم‌های POST و درخواست‌های fetch/XHR هم‌مبدأ */
function sec_csrf_js()
{
    $t = sec_csrf_token();
    if ($t === '') return '';
    return '<script>(function(){var T=' . json_encode($t) . ';if(window.__secT)return;window.__secT=T;'
        . 'function add(f){if(!f||!f.method||String(f.method).toLowerCase()!=="post")return;var a=f.getAttribute("action")||"";try{if(a&&new URL(a,location.href).origin!==location.origin)return;}catch(e){return;}'
        . 'var i=f.querySelector("input[name=csrf_token]");if(!i){i=document.createElement("input");i.type="hidden";i.name="csrf_token";f.appendChild(i);}if(!i.value)i.value=T;}'
        . 'function all(){Array.prototype.forEach.call(document.forms,add);}'
        . 'document.addEventListener("submit",function(e){add(e.target);},true);'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",all);else all();'
        . 'if(window.MutationObserver)new MutationObserver(all).observe(document.documentElement,{childList:true,subtree:true});'
        . 'var F=window.fetch;if(F)window.fetch=function(u,o){try{var url=new URL(typeof u==="string"?u:(u&&u.url)||"",location.href);if(url.origin===location.origin){o=o||{};var h=new Headers(o.headers||(u&&u.headers)||{});if(!h.has("X-CSRF-Token"))h.set("X-CSRF-Token",T);o.headers=h;}}catch(e){}return F.call(this,u,o);};'
        . 'var O=XMLHttpRequest.prototype.open,S=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m,u){this.__same=true;try{this.__same=new URL(u,location.href).origin===location.origin;}catch(e){}return O.apply(this,arguments);};'
        . 'XMLHttpRequest.prototype.send=function(b){try{if(this.__same)this.setRequestHeader("X-CSRF-Token",T);}catch(e){}return S.apply(this,arguments);};'
        . '})();</script>';
}

// ─────────────────────── تله ربات فرم‌های ورود ───────────────────────
/** فیلدهای مخفی فرم: یک فیلد تله (باید خالی بماند) + زمان امضاشده نمایش فرم */
function sec_form_fields($part = 'all')
{
    $t = time();
    $sig = substr(hash_hmac('sha256', 'ft|' . $t, sec_secret()), 0, 24);
    $ft = '<input type="hidden" name="_ft" value="' . $t . '.' . $sig . '">';
    // تله ربات — انتهای فرم (بعد از فیلد رمز) تا «ذخیره رمز» مرورگر آن را فیلد نام کاربری فرض نکند و پرش نکند
    $hp = '<div aria-hidden="true" style="position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important;opacity:0!important;pointer-events:none!important"><label>وب‌سایت<input type="text" name="website_url" value="" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other"></label></div>';
    return $part === 'ft' ? $ft : ($part === 'hp' ? $hp : $hp . $ft);
}

/** بافر خروجی: افزودن خودکار فیلدهای تله به همه فرم‌های POST صفحه */
function sec_form_guard_output()
{
    ob_start(function ($html) {
        if (stripos($html, '<form') === false) return $html;
        $html = preg_replace_callback('/(<form\b[^>]*>)(.*?)(<\/form>)/is', function ($m) {
            if (!preg_match('/method\s*=\s*["\']?post/i', $m[1])) return $m[0];
            return $m[1] . sec_form_fields('ft') . $m[2] . sec_form_fields('hp') . $m[3];
        }, $html);
        // فرم POST بدون </form> در همین خروجی: فیلدها مثل قبل بلافاصله بعد از <form>
        return preg_replace_callback('/<form\b[^>]*>(?!<input type="hidden" name="_ft")/i', function ($m) {
            return preg_match('/method\s*=\s*["\']?post/i', $m[0]) ? $m[0] . sec_form_fields() : $m[0];
        }, $html);
    });
}

/** بررسی ارسال فرم ورود/ثبت‌نام: ربات‌ها (فیلد تله پر، بدون زمان، یا خیلی سریع) رد می‌شوند */
function sec_form_guard()
{
    if (defined('SEC_BOT_GUARD_OFF')) { sec_form_guard_output(); return; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $bad = '';
        $hp = trim((string)($_POST['website_url'] ?? '')) !== '';
        $ft = (string)($_POST['_ft'] ?? '');
        if ($bad === '' && !preg_match('/^(\d{9,11})\.([a-f0-9]{24})$/', $ft, $m)) $bad = 'no-ft';
        if ($bad === '') {
            $min = defined('SEC_FORM_MIN_SECONDS') ? (float)SEC_FORM_MIN_SECONDS : 1;
            if (!hash_equals(substr(hash_hmac('sha256', 'ft|' . $m[1], sec_secret()), 0, 24), $m[2])) $bad = 'ft-sig';
            elseif (time() - (int)$m[1] < $min) $bad = 'too-fast';
            elseif (time() - (int)$m[1] > 6 * 3600) $bad = 'expired';
            // فیلد تله پر شده: ربات‌ها سریع پر می‌کنند؛ پرشدن خودکار مرورگر (ذخیره رمز) با زمان عادی رد نمی‌شود
            elseif ($hp && time() - (int)$m[1] < 4) $bad = 'honeypot';
            elseif ($hp) sec_log('form-guard honeypot-autofill-allowed ' . ($_SERVER['REQUEST_URI'] ?? ''));
        }
        if ($bad !== '') {
            sec_log('form-guard ' . $bad . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
            if ($bad === 'expired') sec_deny(400, 'صفحه منقضی شده است؛ لطفاً صفحه را تازه کنید و دوباره تلاش کنید.');
            sec_deny(403, 'ارسال فرم تأیید نشد. اگر ربات نیستید، صفحه را تازه کنید و دوباره تلاش کنید.');
        }
    }
    sec_form_guard_output();
}

// ─────────────────────── درخواست سرور به آدرس‌های بیرونی ───────────────────────
/** آیا آدرس به اینترنت عمومی اشاره می‌کند؟ (نه شبکه داخلی/لوکال) */
function sec_url_safe($url)
{
    $p = parse_url((string)$url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    $host = trim($p['host'], '[]');
    if (defined('SEC_ALLOW_LOCAL_URLS')) return true;
    if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || preg_match('/\.(local|internal|localhost)$/i', $host)) return false;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge((array)@gethostbynamel($host), []);
    if (!$ips) return true;   // نام قابل تبدیل نیست؛ درخواست خودش شکست می‌خورد
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}

// ─────────────────────── رمز مدیر کل ───────────────────────
/** رمز ضعیف؟ (کوتاه، فقط عدد/حرف، تکراری یا جزو رایج‌ترین رمزها) */
function sec_weak_password($p)
{
    $p = (string)$p;
    if (strlen($p) < 10) return true;
    $common = ['12345678', '123456789', '1234567890', 'password', 'admin123', 'qwerty123', '11111111', '00000000', 'password1', 'iloveyou', 'admin1234', '1q2w3e4r', 'abc12345', 'qwertyuiop'];
    if (in_array(strtolower($p), $common, true)) return true;
    $classes = (int)preg_match('/[a-z]/', $p) + (int)preg_match('/[A-Z]/', $p) + (int)preg_match('/\d/', $p) + (int)preg_match('/[^a-zA-Z\d]/', $p);
    if ($classes < 2) return true;
    if (count(array_unique(str_split($p))) < 5) return true;
    return false;
}

/** بررسی رمز مدیر: اگر رمز جدید (هش‌شده) در تنظیمات ثبت شده، فقط همان؛ وگرنه رمز config.php */
function sec_admin_password_ok($pdo, $input)
{
    $hash = ($pdo && function_exists('biz_get')) ? (string)biz_get($pdo, 'admin_pw_hash') : '';
    if ($hash !== '') return password_verify((string)$input, $hash);
    return defined('AICHAT_ADMIN_PASSWORD') && (string)AICHAT_ADMIN_PASSWORD !== '' && hash_equals((string)AICHAT_ADMIN_PASSWORD, (string)$input);
}

/** آیا رمز مدیر هنوز رمز پیش‌فرض/ضعیف config.php است؟ */
function sec_admin_password_weak($pdo)
{
    $hash = ($pdo && function_exists('biz_get')) ? (string)biz_get($pdo, 'admin_pw_hash') : '';
    if ($hash !== '') return false;
    return sec_weak_password(defined('AICHAT_ADMIN_PASSWORD') ? AICHAT_ADMIN_PASSWORD : '');
}

/**
 * نسخه ۷۷: نام پوشه پنل مدیریت (برای امنیت، نام قابل‌حدس «admin» عوض شده است: namo1220)
 *  ترتیب: ثابت AICHAT_ADMIN_DIR (اگر تعریف شده) ← پوشه‌ای در ریشه برنامه که فایل‌های پنل مدیریت را دارد ← admin
 *  اگر بعداً نام پوشه را دوباره عوض کنید، کد خودش نام تازه را پیدا می‌کند و نیازی به تغییر کد نیست.
 */
function aichat_admin_dir()
{
    static $d = null;
    if ($d !== null) return $d;
    if (defined('AICHAT_ADMIN_DIR') && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)AICHAT_ADMIN_DIR)) return $d = (string)AICHAT_ADMIN_DIR;
    $root = dirname(__DIR__);
    $found = [];
    foreach (['namo1220', 'admin'] as $c) if (is_file($root . '/' . $c . '/api_settings.php') && is_file($root . '/' . $c . '/_bootstrap.php')) $found[] = $c;
    if (!$found) foreach ((array)glob($root . '/*/api_settings.php') as $f) { $c = basename(dirname($f)); if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $c) && is_file(dirname($f) . '/_bootstrap.php')) $found[] = $c; }
    return $d = ($found[0] ?? 'admin');
}

} // SEC_LOADED
