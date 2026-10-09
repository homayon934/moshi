<?php
/**
 * چت صفحه اصلی سایت — حالت کامل (نسخه ۴۳)
 *
 * داخل پنجره وسط صفحه اصلی (قاب) باز می‌شود و همان صفحه چت چت‌بات‌های تخصصی را برای «چت‌بات سایت» نشان می‌دهد:
 * ورود با موبایل/گوگل، پیام رایگان، بسته‌ها و خرید، انتخاب مدل، ساخت تصویر و ویدیو، فایل و صوت، حافظه و یادآور.
 *
 *  - بازدیدکننده وارد نشده: چند پیام «مهمان» (اگر مدیر اجازه داده باشد) با دکمه «ورود / ثبت‌نام».
 *  - کاربری که در پنل کاربری سایت وارد است: خودکار با همان شماره موبایل وارد می‌شود.
 *  - بعد از ورود، گفتگوی مهمان به‌عنوان یک گفتگو در فهرست گفتگوهای او ذخیره می‌شود.
 * تنظیمات: مدیریت ← تنظیمات سایت ← «دستیار گفتگوی صفحه اصلی».
 */
error_reporting(0);

require_once dirname(__DIR__) . '/includes/sec_lib.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
require_once dirname(__DIR__) . '/includes/site_lib.php';
require_once dirname(__DIR__) . '/includes/sitechat_lib.php';

function sbx_404()
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>404</title></head>'
       . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px;color:#64748b">گفتگو در حال حاضر در دسترس نیست.</body></html>';
    exit;
}

try {
    $pdo = aichat_connect();
    try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
    try { hd_ensure_schema($pdo); } catch (\Throwable $e) {}
    site_ensure_schema($pdo);
    $s = site_settings($pdo);
    $c = sch_conf($s);
    $bot = ($c['on'] && $c['full']) ? sb_bot($pdo) : null;
} catch (\Throwable $e) {
    error_log('[SB] page: ' . $e->getMessage());
    $bot = null;
}
if (!$bot || empty($bot['is_active'])) sbx_404();
@set_time_limit(90);

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$tok = isset($_COOKIE['hdc_t']) ? (string)$_COOKIE['hdc_t'] : '';
$member = $tok !== '' ? hd_member_by_token($pdo, (int)$bot['id'], $tok) : null;
$cookie_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// نشست سایت: ورود خودکار کاربر پنل + گفتگوی مهمان (نشست زود بسته می‌شود تا درخواست‌های طولانی چت، بقیه صفحه‌ها را معطل نکند)
sec_session_start();
$guest_hist = is_array($_SESSION['sch_hist'] ?? null) ? $_SESSION['sch_hist'] : [];
$sso = (!$member && $a === '') ? sb_session_user_mobile($pdo, $_SESSION) : null;
session_write_close();

if ($sso) {
    $r = sb_sso_member($pdo, $bot, $sso['mobile'], $sso['name']);
    if ($r) {
        $member = $r['member'];
        setcookie('hdc_t', $r['token'], ['expires' => time() + 180 * 86400, 'path' => $cookie_path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['hdc_t'] = $r['token'];
        if ($guest_hist && sb_import_guest($pdo, $bot, $member, $guest_hist)) { sec_session_start(); unset($_SESSION['sch_hist']); session_write_close(); }
    }
}

// مهمان (وارد نشده): صفحه گفتگوی سبک با دکمه ورود؛ ورود، خرید، گوگل و API همیشه با صفحه کامل
$want_full = $a !== '' || isset($_GET['login']) || isset($_GET['pay']) || isset($_GET['plans']) || isset($_GET['rem']);
if (!$member && !$want_full && $c['guest'] > 0) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $login = $cookie_path . '?login=1' . (isset($_GET['embed']) ? '&embed=' . ((string)$_GET['embed'] === '2' ? '2' : '1') : '');
    $ep = rtrim((string)parse_url(AICHAT_BASE_URL, PHP_URL_PATH), '/') . '/site_chat.php';
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex">'
       . '<title>' . htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') . '</title>'
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet">'
       . '<style>html,body{margin:0;height:100%;background:#050816;font-family:\'Vazirmatn\',Tahoma,sans-serif}</style></head><body>'
       . sch_widget_html($c, $ep, ['page' => true, 'login' => $login, 'guest' => $c['guest']])
       . '</body></html>';
    exit;
}

// صفحه کامل چت (همان فایل رابط چت‌بات‌ها، اجرای مستقیم روی همین سرور)
$GLOBALS['hdc_local_pdo'] = $pdo;
$GLOBALS['hdc_local_bot'] = $bot;
$GLOBALS['hdc_local_after'] = function ($payload, $r) use ($pdo, $bot, $guest_hist) {
    // پس از ورود موفق: انتقال گفتگوی مهمان به گفتگوهای همین مخاطب
    if ($guest_hist && !empty($r['token']) && in_array((string)($payload['action'] ?? ''), ['login', 'verify', 'pw_login', 'g_finish', 'g_register', 'set_password'], true)) {
        $m = hd_member_by_token($pdo, (int)$bot['id'], (string)$r['token']);
        if ($m && sb_import_guest($pdo, $bot, $m, $guest_hist)) {
            if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
            unset($_SESSION['sch_hist']);
            session_write_close();
        }
    }
    return $r;
};
define('HD_LOCAL', true);
define('HD_SERVER', 'local');
define('HD_KEY', $bot['bot_key']);
define('HDC_CACHE_DIR', __DIR__ . '/cache');

require dirname(__DIR__) . '/hamdam/connector/index.php';
