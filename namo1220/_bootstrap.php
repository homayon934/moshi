<?php
require_once dirname(__DIR__) . '/includes/sec_lib.php';
sec_boot('admin');   // امنیت: نشست امن، WAF، محدودیت درخواست، هدرها
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/panel_ajax.php';
panel_ajax_init();   // ذخیره بدون رفرش کامل صفحه
require_once dirname(__DIR__) . '/includes/saas_lib.php';

try {
    $pdo = aichat_connect();
} catch (\Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(503);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطای اتصال</title>'
       . '<style>body{font-family:Tahoma,sans-serif;background:#fef2f2;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.box{background:#fff;border:1px solid #fecaca;border-radius:12px;padding:32px;max-width:480px;text-align:center}'
       . 'h2{color:#991b1b;margin-bottom:12px}p{color:#6b7280;font-size:14px}</style></head>'
       . '<body><div class="box"><h2>⚠️ خطای اتصال به پایگاه داده</h2>'
       . '<p>اتصال به دیتابیس برقرار نشد. لطفاً تنظیمات config.php را بررسی کنید یا با پشتیبانی تماس بگیرید.</p>'
       . '</div></body></html>';
    exit;
}
try { saas_ensure_schema($pdo); } catch (\Throwable $e) { error_log('saas_ensure_schema: ' . $e->getMessage()); }

// ── Security Headers ──────────────────────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// ── Site Lib (auto-load if exists) ────────────────────────────────────────
$_slib = dirname(__DIR__) . '/includes/site_lib.php';
if (file_exists($_slib)) {
    require_once $_slib;
    try { site_ensure_schema($pdo); } catch (\Throwable $e) { error_log($e->getMessage()); }
}

// ── Auth ──────────────────────────────────────────────────────────────────
$admin_logged_in = !empty($_SESSION['aichat_admin']);
$current_page    = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME']));   // نه PHP_SELF (قابل جعل با /page.php/login.php)
$public_pages    = ['login.php'];

if (!$admin_logged_in && !in_array($current_page, $public_pages)) {
    header('Location: login.php'); exit;
}

// پایان خودکار نشست مدیر بعد از ۲ ساعت بی‌فعالیتی
if ($admin_logged_in) {
    $idle = time() - (int)($_SESSION['aichat_admin_seen'] ?? time());
    if ($idle > 7200 && $current_page !== 'login.php') {
        unset($_SESSION['aichat_admin'], $_SESSION['aichat_admin_seen']);
        header('Location: login.php?msg=' . urlencode('برای امنیت، به‌دلیل بی‌فعالیتی از پنل خارج شدید؛ دوباره وارد شوید.')); exit;
    }
    $_SESSION['aichat_admin_seen'] = time();
    if (function_exists('act_session_login')) act_session_login($pdo, 'admin', 0, ['name' => 'مدیر کل', 'title' => 'پنل مدیریت']);   // گزارش ورود (نسخه ۴۷)
    // جلوگیری از جعل درخواست (CSRF) برای همه فرم‌ها و لینک‌های حذف/تغییر
    sec_csrf_check();
    sec_get_guard();
    // رمز پیش‌فرض/ضعیف: تا تغییر رمز، فقط صفحه امنیت باز می‌شود
    if (!isset($_SESSION['adm_pw_checked'])) {
        $_SESSION['adm_pw_checked'] = 1;
        $pw_on = !function_exists('comm_login_is') || comm_login_is($pdo, 'admin', 'password');
        if ($pw_on && sec_admin_password_weak($pdo)) $_SESSION['adm_pw_weak'] = 1;
    }
    if (!empty($_SESSION['adm_pw_weak']) && !sec_admin_password_weak($pdo)) unset($_SESSION['adm_pw_weak']);
    if (!empty($_SESSION['adm_pw_weak']) && !in_array($current_page, ['security.php', 'login.php', 'logout.php'], true)) { header('Location: security.php?pw=1#pw'); exit; }
}
if ($admin_logged_in && function_exists('rem_maybe_run')) rem_maybe_run($pdo);   // یادآورهای موعدرسیده

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php?msg=خروج+با+موفقیت+انجام+شد'); exit;
}

// ── CSRF Token ────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── Helper Functions ──────────────────────────────────────────────────────
if (!function_exists('admin_h')) {
    function admin_h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('saas_h')) {
    function saas_h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('saas_fmt_num')) {
    function saas_fmt_num(mixed $n): string { return number_format((int)$n); }
}
if (!function_exists('verify_csrf')) {
    function verify_csrf(string $session_tok, string $post_tok): bool {
        if (empty($session_tok) || empty($post_tok)) return false;
        return hash_equals($session_tok, $post_tok);
    }
}
