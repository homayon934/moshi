<?php
/**
 * کارهای زمان‌بندی‌شده (یادآوری انقضای اشتراک کاربران و بسته‌های مخاطبان)
 *
 * اجرا در cPanel → Cron Jobs (هر ساعت یا روزانه):
 *   wget -q -O /dev/null "https://tum.ir/ai-chat/cron.php?key=کلید_مخصوص"
 * یا:
 *   php /home/USER/public_html/ai-chat/cron.php
 * کلید مخصوص در پنل ادمین → تنظیمات API نمایش داده می‌شود.
 * (بدون Cron هم یادآوری‌ها با بازدید سایت/پنل، ساعتی یک بار اجرا می‌شوند.)
 */
// اجرا از Cron سرور؟ در cPanel مسیر /usr/local/bin/php گاهی نسخه CGI است (PHP_SAPI = cgi-fcgi) و $argv ندارد؛
// اجرای خط فرمان را از نبودن درخواست وب (REQUEST_METHOD / HTTP_HOST) تشخیص می‌دهیم.
$cli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || (!isset($_SERVER['REQUEST_METHOD']) && !isset($_SERVER['HTTP_HOST']) && !isset($_SERVER['REMOTE_ADDR']));
@chdir(__DIR__);
// ردپای اجرا و خطا (بدون نیاز به دیتابیس) — برای نمایش در پنل مدیر
$beat = __DIR__ . '/uploads/.cron_beat';
$fail = function ($msg) use ($beat) {
    @file_put_contents($beat, json_encode(['ts' => time(), 'at' => date('Y-m-d H:i:s'), 'sapi' => PHP_SAPI, 'err' => mb_substr((string)$msg, 0, 500)], JSON_UNESCAPED_UNICODE));
};
register_shutdown_function(function () use ($fail) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) $fail($e['message'] . ' @' . basename($e['file']) . ':' . $e['line']);
});
@file_put_contents($beat, json_encode(['ts' => time(), 'at' => date('Y-m-d H:i:s'), 'sapi' => PHP_SAPI, 'err' => ''], JSON_UNESCAPED_UNICODE));

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/saas_lib.php';
if (is_file(__DIR__ . '/includes/hamdam_lib.php')) require_once __DIR__ . '/includes/hamdam_lib.php';

if (!$cli && !headers_sent()) header('Content-Type: text/plain; charset=utf-8');

try { $pdo = aichat_connect(); } catch (\Throwable $e) { $fail('اتصال دیتابیس: ' . $e->getMessage()); exit("db error\n"); }
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
if (function_exists('hd_ensure_schema')) { try { hd_ensure_schema($pdo); } catch (\Throwable $e) {} }

if (!$cli) {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string)biz_get($pdo, 'cron_key'), $key)) {
        $fail('درخواست بدون کلید درست رد شد (SAPI: ' . PHP_SAPI . ')');
        http_response_code(403);
        exit("forbidden\n");
    }
}

@set_time_limit(300);
if (function_exists('biz_set')) biz_set($pdo, 'rem_cron_last', (string)time());   // همین که Cron اجرا شد، هشدار پنل مدیر پنهان می‌شود
try {
// یادآورها: هر بار (پیشنهاد: Cron هر ۱ دقیقه)؛ کارهای ساعتی فقط ساعتی یک بار (با ?force=1 همیشه)
$rn = function_exists('rem_run_due') ? rem_run_due($pdo) : 0;
if (function_exists('biz_set')) biz_set($pdo, 'rem_cron_last', (string)time());   // برای هشدار «Cron تنظیم نشده» در پنل مدیر
$r = biz_run_cron($pdo, ($cli && in_array('force', $argv ?? [], true)) || isset($_GET['force']));   // کارهای ساعتی: ساعتی یک بار (حتی با Cron دقیقه‌ای)
} catch (\Throwable $e) {
    $fail($e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    exit('ERROR ' . $e->getMessage() . "\n");
}
echo 'OK ' . date('Y-m-d H:i:s') . ' reminders=' . (int)$rn . (!empty($r['skipped']) ? ' hourly=skipped' : ' users=' . (int)($r['users'] ?? 0) . ' members=' . (int)($r['members'] ?? 0)) . "\n";
