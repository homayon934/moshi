<?php
/**
 * اجزای مشترک صفحه‌های ورود (پنل کاربران، ثبت‌نام، مدیر کل، همکاری در فروش)
 */
if (defined('COMM_UI_LOADED')) return;
define('COMM_UI_LOADED', 1);

/** نام نمایشی سایت (بدون نام سامانه سازنده) */
function comm_brand($pdo)
{
    $name = '';
    try {
        if (!function_exists('site_settings') && is_file(__DIR__ . '/site_lib.php')) require_once __DIR__ . '/site_lib.php';
        if (function_exists('site_settings')) $name = trim((string)(site_settings($pdo)['site_name'] ?? ''));
    } catch (\Throwable $e) {}
    if ($name === '') $name = preg_replace('/^www\./', '', (string)parse_url(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', PHP_URL_HOST)) ?: 'پنل';
    return $name;
}

/** مسیر امن بازگشت پس از ورود (فقط مسیرهای همین سایت) */
function comm_safe_next($next, $def = 'index.php')
{
    $next = (string)$next;
    if ($next === '' || strpos($next, '//') !== false || strpos($next, '\\') !== false || !preg_match('#^[A-Za-z0-9_\-/.?=&%]+$#', $next)) return $def;
    return $next;
}

function comm_auth_css($accent = '#7c3aed', $bg = 'linear-gradient(135deg,#1e1b4b 0%,#312e81 100%)')
{
    return '<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Vazirmatn,"Segoe UI",Tahoma,sans-serif;background:' . $bg . ';min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;direction:rtl}
.box{background:#fff;border-radius:16px;padding:30px;width:100%;max-width:410px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
h1{font-size:20px;color:#1e1b4b;font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:8px}
.sub{font-size:13px;color:#64748b;margin-bottom:18px;line-height:1.9}
.form-group{margin-bottom:14px}
label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px}
input,select{width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:9px;font-size:14px;font-family:inherit;transition:.2s;background:#fff}
input:focus,select:focus{outline:none;border-color:' . $accent . ';box-shadow:0 0 0 3px rgba(124,58,237,.12)}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:' . $accent . ';color:#fff;border:none;border-radius:9px;cursor:pointer;font-size:15px;font-weight:700;font-family:inherit;margin-top:4px;transition:.2s;text-decoration:none}
.btn:hover{filter:brightness(.93)}
.btn-o{background:#fff;color:#1f2937;border:1.5px solid #d1d5db}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;line-height:1.9}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.links{margin-top:16px;text-align:center;font-size:13px;color:#64748b;line-height:2.1}
.links a{color:' . $accent . ';text-decoration:none}
.links a:hover{text-decoration:underline}
.captcha-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px}
.captcha-question{font-size:21px;font-weight:800;color:#1e1b4b;text-align:center;letter-spacing:3px;direction:ltr;margin-bottom:8px}
.mtabs{display:flex;gap:4px;background:#f1f5f9;border-radius:11px;padding:4px;margin-bottom:16px}
.mtabs a{flex:1;text-align:center;padding:8px 4px;border-radius:8px;font-size:12.5px;font-weight:600;color:#475569;text-decoration:none;white-space:nowrap}
.mtabs a.on{background:#fff;color:' . $accent . ';box-shadow:0 1px 4px rgba(0,0,0,.08)}
.otp{font-size:22px;letter-spacing:8px;text-align:center;direction:ltr;font-weight:700}
.acc{display:flex;gap:10px;align-items:center;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-bottom:8px;cursor:pointer}
.acc input{width:auto}
.acc small{color:#64748b;display:block;font-size:11.5px}
.sep{display:flex;align-items:center;gap:10px;margin:14px 0 10px;color:#94a3b8;font-size:12px}.sep:before,.sep:after{content:"";flex:1;height:1px;background:#e2e8f0}
</style>';
}

function comm_google_icon()
{
    return '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
}

/** نوار انتخاب روش ورود (به ترتیب اولویت) */
function comm_method_tabs(array $methods, $current, $base)
{
    if (count($methods) < 2) return '';
    $labels = ['sms' => '📩 کد پیامکی', 'google' => 'گوگل', 'password' => '🔑 رمز عبور'];
    $h = '<nav class="mtabs">';
    foreach ($methods as $m) $h .= '<a href="' . comm_h($base . (strpos($base, '?') === false ? '?' : '&') . 'm=' . $m) . '" class="' . ($m === $current ? 'on' : '') . '">' . ($m === 'google' ? comm_google_icon() . ' ' : '') . $labels[$m] . '</a>';
    return $h . '</nav>';
}

function comm_captcha_field($captcha)
{
    return '<div class="form-group"><label>تأیید امنیتی — حاصل عملیات زیر را وارد کنید</label><div class="captcha-box">'
         . '<div class="captcha-question">' . comm_h($captcha['question']) . ' = ?</div>'
         . '<input type="hidden" name="captcha_token" value="' . comm_h($captcha['token']) . '">'
         . '<input type="text" inputmode="numeric" name="captcha_answer" required dir="ltr" placeholder="پاسخ" autocomplete="off" maxlength="5"></div></div>';
}

function comm_mask_email($e)
{
    $e = (string)$e;
    if (strpos($e, '@') === false) return $e;
    [$u, $d] = explode('@', $e, 2);
    return mb_substr($u, 0, 2) . str_repeat('•', max(2, min(6, mb_strlen($u) - 2))) . '@' . $d;
}

function comm_mask_mobile($m) { return strlen((string)$m) >= 11 ? substr($m, 0, 4) . '•••' . substr($m, -4) : (string)$m; }
