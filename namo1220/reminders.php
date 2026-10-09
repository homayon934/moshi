<?php
/**
 * یادآورهای مدیر کل: برای خودش، کاربران و همکاران کاربران
 * (اعلان داخل پنل، اعلان مرورگر روی گوشی/کامپیوتر، پیامک با پنل پیامک سامانه)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/rem_ui.php';
rem_ensure_schema($pdo);
$ctx = rem_ctx_admin($pdo);
rem_ui_ajax($pdo, $ctx);
$base = rtrim(AICHAT_BASE_URL, '/');
include __DIR__ . '/_header.php';
echo '<h2 class="page-title">⏰ یادآورها و برنامه‌ها</h2>';
echo '<p style="color:#64748b;font-size:13px;margin:-6px 0 14px">برای خودتان، کاربران یا همکاران کاربران یادآور بگذارید. کاربر یادآور را در پنل خودش (زنگوله و پنجره یادآور)، روی گوشی (اعلان مرورگر) و در صورت انتخاب با پیامک دریافت می‌کند.</p>';
if (function_exists('rem_cron_ok') && !rem_cron_ok($pdo)) {
    // بدون Cron، یادآورها فقط وقتی کسی سایت را باز کند ارسال می‌شوند (اعلان در زمان بسته بودن سایت دیر می‌رسد)
    $bsx = biz_settings($pdo, true);
    $cron_cmd = 'wget -q -O /dev/null "' . $base . '/cron.php?key=' . $bsx['cron_key'] . '"';
    echo '<div class="alert alert-warning" style="line-height:2"><b>⚠️ زمان‌بند (Cron) سرور هنوز تنظیم نشده است.</b> برای اینکه اعلان و پیامک یادآورها دقیقاً سر وقت (حتی وقتی هیچ‌کس سایت را باز نکرده) ارسال شود، در cPanel ← <b>Cron Jobs</b> یک کار با زمان‌بندی <b dir="ltr">* * * * *</b> (هر دقیقه) و این دستور بسازید:'
       . '<div dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:8px 10px;margin-top:6px;font-family:monospace;font-size:12px;word-break:break-all;user-select:all">' . admin_h('/usr/local/bin/php ' . dirname(__DIR__) . '/cron.php') . '</div>'
       . '<small>یا (اگر دستور بالا کار نکرد):</small><div dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:8px 10px;margin-top:4px;font-family:monospace;font-size:12px;word-break:break-all;user-select:all">' . admin_h($cron_cmd) . '</div>'
       . '<small style="color:#92400e">بعد از اولین اجرا، این پیام خودکار پنهان می‌شود.</small>';
    // عیب‌یابی: آیا Cron اصلاً فایل را اجرا کرده؟ (ردپای cron.php در uploads/.cron_beat)
    $beat = json_decode((string)@file_get_contents(dirname(__DIR__) . '/uploads/.cron_beat'), true);
    if (is_array($beat) && (!empty($beat['ts']) || !empty($beat['at']))) {
        $ago = max(0, time() - (!empty($beat['ts']) ? (int)$beat['ts'] : (int)strtotime((string)$beat['at'])));
        echo '<div style="margin-top:8px;padding:8px 10px;border-radius:8px;background:#fff;border:1px solid #fcd34d;font-size:12.5px">🔎 <b>آخرین اجرای cron.php:</b> ' . admin_h($ago < 120 ? 'همین الان' : (round($ago / 60) . ' دقیقه پیش')) . ' <span dir="ltr">(' . admin_h((string)($beat['sapi'] ?? '')) . ')</span>'
           . (!empty($beat['err']) ? '<br><b style="color:#b91c1c">خطا:</b> <span dir="auto">' . admin_h((string)$beat['err']) . '</span>' : ($ago < 300 ? '<br>✅ Cron کار می‌کند؛ صفحه را تازه کنید.' : '')) . '</div>';
    } else {
        echo '<div style="margin-top:8px;padding:8px 10px;border-radius:8px;background:#fff;border:1px solid #fcd34d;font-size:12.5px">🔎 cron.php تا حالا حتی یک بار هم اجرا نشده است: مسیر فایل در دستور Cron را دقیقاً مثل بالا بنویسید (حروف کوچک و بزرگ مهم است) یا دستور دوم (wget) را امتحان کنید. اگر ایمیل cPanel تنظیم شده باشد، خروجی هر اجرا به ایمیل شما می‌آید.</div>';
    }
    echo '</div>';
}
echo rem_ui_render($pdo, $ctx, ['push_url' => $base . '/push.php?act=sub&as=admin', 'sw_url' => $base . '/sw.php', 'sms_note' => 'پیامک یادآورهای مدیر با پنل پیامک سامانه ارسال می‌شود (برای «خودم»: اولین شماره مدیر در «پیامک و ورود»).']);
include __DIR__ . '/_footer.php';
